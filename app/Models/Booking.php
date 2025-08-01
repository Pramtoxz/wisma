<?php

namespace App\Models;

use CodeIgniter\Model;

class Booking extends Model
{
    protected $table            = 'booking';
    protected $primaryKey       = 'idbooking';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'idbooking', 'id_pasien', 'idjadwal', 'idjenis', 'tanggal', 
        'waktu_mulai', 'waktu_selesai', 'status', 'bukti_bayar', 'catatan', 'online', 'konsultasi', 'created_at'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules = [];
    protected $validationMessages = [];
    protected $skipValidation = false;
    
    // Cek ketersediaan jadwal
    public function isSlotAvailable($idjadwal, $tanggal, $waktu_mulai, $waktu_selesai, $exclude_id = null)
    {
        $builder = $this->builder();
        $builder->where('idjadwal', $idjadwal);
        $builder->where('tanggal', $tanggal);
        $builder->where('deleted_at IS NULL');
        
        // Kondisi untuk cek overlap waktu
        $builder->groupStart()
            ->where("(waktu_mulai < '$waktu_selesai' AND waktu_selesai > '$waktu_mulai')")
        ->groupEnd();
        
        // Jika ada ID yang dikecualikan (untuk keperluan edit)
        if ($exclude_id) {
            $builder->where('idbooking !=', $exclude_id);
        }
        
        $count = $builder->countAllResults();
        return ($count == 0);
    }
    
    // Mencari slot waktu tersedia dalam range waktu tertentu
    public function findAvailableSlot($idjadwal, $tanggal, $blok_waktu, $durasi_menit)
    {
        // Ambil jadwal dokter dari database
        $db = \Config\Database::connect();
        $jadwal = $db->table('jadwal')
                    ->where('idjadwal', $idjadwal)
                    ->get()
                    ->getRowArray();
        
        if (!$jadwal) {
            return null; // Jadwal tidak ditemukan
        }
        
        // Gunakan waktu dari jadwal dokter, bukan hardcoded value
        $waktu_mulai_jadwal = $jadwal['waktu_mulai'];
        $waktu_selesai_jadwal = $jadwal['waktu_selesai'];
        
        // Ambil semua booking pada jadwal dan tanggal tersebut
        $existing_bookings = $this->where('idjadwal', $idjadwal)
                                 ->where('tanggal', $tanggal)
                                 ->where('waktu_mulai >=', $waktu_mulai_jadwal)
                                 ->where('waktu_selesai <=', $waktu_selesai_jadwal)
                                 ->where('status !=', 'ditolak')
                                 ->orderBy('waktu_mulai', 'ASC')
                                 ->findAll();
        
        // Buffer time antar pasien (dalam menit)
        // Ini bisa juga diambil dari konfigurasi atau database jika perlu
        $buffer_time = 10;
        
        // Konversi durasi dan buffer ke format time interval (jam)
        $durasi_jam = $durasi_menit / 60;
        $buffer_jam = $buffer_time / 60;
        
        // Inisialisasi waktu mulai pencarian dengan waktu mulai jadwal
        $current_time = $waktu_mulai_jadwal;
        
        // Iterasi melalui existing bookings untuk menemukan slot kosong
        foreach ($existing_bookings as $booking) {
            $slot_end_time = date('H:i:s', strtotime($current_time) + ($durasi_menit * 60));
            
            // Jika slot yang tersedia cukup sebelum booking berikutnya
            if (strtotime($slot_end_time) <= strtotime($booking['waktu_mulai'])) {
                return [
                    'waktu_mulai' => $current_time,
                    'waktu_selesai' => $slot_end_time
                ];
            }
            
            // Update current_time ke akhir booking + buffer
            $current_time = date('H:i:s', strtotime($booking['waktu_selesai']) + ($buffer_time * 60));
        }
        
        // Cek apakah masih ada slot setelah booking terakhir
        $slot_end_time = date('H:i:s', strtotime($current_time) + ($durasi_menit * 60));
        if (strtotime($slot_end_time) <= strtotime($waktu_selesai_jadwal)) {
            return [
                'waktu_mulai' => $current_time,
                'waktu_selesai' => $slot_end_time
            ];
        }
        
        // Jika tidak ada slot yang tersedia
        return null;
    }
    
    // Mencari slot waktu tersedia berdasarkan jadwal dokter
    public function findAvailableSlotFromSchedule($idjadwal, $tanggal, $durasi_menit, $is_walk_in = false, $exclude_idbooking = null)
    {
        // Set timezone ke Waktu Indonesia Barat (WIB)
        date_default_timezone_set('Asia/Jakarta');
        
        // Get jadwal details
        $db = \Config\Database::connect();
        $jadwal = $db->table('jadwal')
                    ->where('idjadwal', $idjadwal)
                    ->get()
                    ->getRowArray();
        
        if (!$jadwal) {
            log_message('error', "Jadwal tidak ditemukan: $idjadwal");
            return null;
        }
        
        // Validasi apakah tanggal sesuai dengan hari jadwal dokter
        $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $dayOfWeek = date('w', strtotime($tanggal)); // 0 (Minggu) sampai 6 (Sabtu)
        $dayName = $dayNames[$dayOfWeek];
        
        log_message('debug', "Checking day match: Selected date $tanggal is $dayName, schedule day is {$jadwal['hari']}");
        
        if ($dayName !== $jadwal['hari']) {
            log_message('error', "Hari tidak sesuai: $dayName != {$jadwal['hari']}");
            return null; // Tanggal tidak sesuai dengan hari jadwal
        }
        
        // Ambil waktu mulai dan selesai dari jadwal dokter - GUNAKAN DARI DATABASE
        $waktu_mulai_jadwal = $jadwal['waktu_mulai'];
        $waktu_selesai_jadwal = $jadwal['waktu_selesai'];
        
        log_message('debug', "Jadwal waktu: $waktu_mulai_jadwal - $waktu_selesai_jadwal");
        
        // Ambil semua booking pada jadwal dan tanggal tersebut, kecuali booking yang sedang diedit
        $query = $this->where('idjadwal', $idjadwal)
                    ->where('tanggal', $tanggal)
                    ->where('status !=', 'ditolak');
                    
        // Jika sedang mengedit booking, kecualikan booking yang sedang diedit
        if ($exclude_idbooking) {
            $query->where('idbooking !=', $exclude_idbooking);
            log_message('debug', "Mengecualikan booking dengan ID: $exclude_idbooking");
        }
        
        $existing_bookings = $query->orderBy('waktu_mulai', 'ASC')->findAll();
        
        log_message('debug', "Jumlah booking yang ada: " . count($existing_bookings));
        
        // Buffer time antar pasien (dalam menit) - bisa diambil dari konfigurasi jika perlu
        $buffer_time = 0;
        
        // PERBAIKAN LOGIKA: Tentukan waktu mulai berdasarkan kondisi
        $today = date('Y-m-d');
        $current_time = $waktu_mulai_jadwal; // Default menggunakan waktu mulai jadwal
        
        // Jika ada booking sebelumnya, gunakan waktu selesai booking terakhir + buffer
        if (count($existing_bookings) > 0) {
            $lastBooking = end($existing_bookings);
            $current_time = date('H:i:s', strtotime($lastBooking['waktu_selesai']) + ($buffer_time * 60));
            log_message('debug', "Menggunakan waktu selesai booking terakhir + buffer: $current_time");
        } else {
            // Jika tidak ada booking sebelumnya, tentukan waktu mulai berdasarkan tanggal
            if ($tanggal == $today) {
                $currentTime = date('H:i:s');
                log_message('debug', "Tanggal hari ini. Waktu saat ini: $currentTime");
                
                // Jika waktu saat ini sebelum jadwal dimulai
                if ($currentTime < $waktu_mulai_jadwal) {
                    $current_time = $waktu_mulai_jadwal;
                    log_message('debug', "Waktu saat ini sebelum jadwal. Menggunakan waktu mulai jadwal: $current_time");
                } else if ($currentTime < $waktu_selesai_jadwal) {
                    // PERBAIKAN: Gunakan waktu saat ini dengan buffer minimal
                    // Buffer yang lebih kecil: 5 menit untuk online, 0 untuk walk-in
                    $buffer_minutes = $is_walk_in ? 0 : 5;
                    $waktu_mulai_minimal = date('H:i:s', strtotime($currentTime) + ($buffer_minutes * 60));
                    
                    // Pastikan waktu mulai masih dalam rentang jadwal
                    if (strtotime($waktu_mulai_minimal) < strtotime($waktu_selesai_jadwal)) {
                        $current_time = $waktu_mulai_minimal;
                        log_message('debug', "Waktu saat ini dalam jadwal. Waktu mulai + buffer {$buffer_minutes} menit: $current_time");
                    } else {
                        // Jika dengan buffer melebihi jadwal, gunakan waktu saat ini
                        $current_time = $currentTime;
                        log_message('debug', "Buffer melebihi jadwal. Menggunakan waktu saat ini: $current_time");
                    }
                } else {
                    // Jika waktu saat ini sudah melewati jadwal
                    log_message('error', "Waktu saat ini sudah melewati jadwal: $currentTime > $waktu_selesai_jadwal");
                    return null; // Tidak ada slot tersedia
                }
            } else {
                // Jika tanggal di masa depan, gunakan waktu awal jadwal
                $current_time = $waktu_mulai_jadwal;
                log_message('debug', "Tanggal masa depan. Menggunakan waktu mulai jadwal: $current_time");
            }
        }
        
        // Menggunakan fungsi yang konsisten untuk membandingkan waktu
        // Konversi waktu ke menit sejak tengah malam untuk perhitungan yang lebih akurat
        $current_time_minutes = $this->timeToMinutes($current_time);
        $waktu_selesai_minutes = $this->timeToMinutes($waktu_selesai_jadwal);
        
        log_message('debug', "Cek waktu mulai vs selesai: $current_time ($current_time_minutes menit) vs $waktu_selesai_jadwal ($waktu_selesai_minutes menit)");
        
        // Jika waktu mulai yang disesuaikan sudah melewati waktu selesai jadwal, tidak ada slot tersedia
        if ($current_time_minutes >= $waktu_selesai_minutes) {
            log_message('error', "Waktu mulai sudah melewati waktu selesai jadwal");
            return null;
        }
        
        // Hitung waktu slot berdasarkan current_time dan durasi
        $slot_end_minutes = $current_time_minutes + $durasi_menit;
        $slot_end_time = $this->minutesToTime($slot_end_minutes);
        
        log_message('debug', "Durasi perawatan: $durasi_menit menit. Waktu selesai slot: $slot_end_time ($slot_end_minutes menit)");
        
        // Periksa apakah slot berakhir dalam waktu jadwal
        if ($slot_end_minutes <= $waktu_selesai_minutes) {
            log_message('debug', "Slot tersedia: $current_time - $slot_end_time");
            return [
                'waktu_mulai' => $current_time,
                'waktu_selesai' => $slot_end_time
            ];
        }
        
        log_message('error', "Slot berakhir setelah jadwal selesai: $slot_end_time > $waktu_selesai_jadwal");
        
        // TAMBAHAN: Coba berikan slot alternatif jika memungkinkan dengan durasi yang lebih pendek
        $remaining_minutes = $waktu_selesai_minutes - $current_time_minutes;
        if ($remaining_minutes >= 30) { // Minimal 30 menit untuk sesi perawatan (bisa diambil dari konfigurasi)
            log_message('debug', "Mencoba memberikan slot alternatif dengan waktu yang tersisa: $remaining_minutes menit");
            $alt_slot_end_time = $this->minutesToTime($waktu_selesai_minutes);
            
            return [
                'waktu_mulai' => $current_time,
                'waktu_selesai' => $alt_slot_end_time,
                'warning' => "Waktu yang tersedia hanya $remaining_minutes menit dari $durasi_menit menit yang dibutuhkan."
            ];
        }
        
        // Jika tidak ada slot yang tersedia
        return null;
    }
    
    // Helper functions untuk konversi waktu
    private function timeToMinutes($time) {
        list($hours, $minutes, $seconds) = explode(':', $time);
        return ($hours * 60) + $minutes;
    }
    
    private function minutesToTime($minutes) {
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return sprintf('%02d:%02d:00', $hours, $mins);
    }
} 