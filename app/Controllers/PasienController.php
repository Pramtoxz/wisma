<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\Pasien as ModelPasien;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Hermawan\DataTables\DataTable;

class PasienController extends BaseController
{
    public function index()
    {
        $title = [
            'title' => 'Kelola Data Pasien'
        ];
        return view('pasien/datapasien', $title);
    }

    public function viewPasien()
    {
        $db = db_connect();
        $query = $db->table('pasien')
                    ->select('id_pasien, nama, nohp, jenkel, iduser');

        return DataTable::of($query)
        ->add('action', function ($row) {
            $button1 = '<button type="button" class="btn btn-primary btn-sm btn-detail" data-id_pasien="' . $row->id_pasien . '" data-toggle="modal" data-target="#detailModal"><i class="fas fa-eye"></i></button>';
            $button2 = '<button type="button" class="btn btn-secondary btn-sm btn-edit" data-id_pasien="' . $row->id_pasien . '" style="margin-left: 5px;"><i class="fas fa-pencil-alt"></i></button>';
            $button3 = '<button type="button" class="btn btn-danger btn-sm btn-delete" data-id_pasien="' . $row->id_pasien . '" style="margin-left: 5px;"><i class="fas fa-trash"></i></button>';
            
            // Tambahkan tombol kunci untuk membuat user jika iduser NULL
            $button4 = '';
            if ($row->iduser === null) {
                $button4 = '<button type="button" class="btn btn-warning btn-sm btn-create-user" data-id_pasien="' . $row->id_pasien . '" data-toggle="modal" data-target="#createUserModal" style="margin-left: 5px;"><i class="fas fa-key"></i></button>';
            }
            
            $buttonsGroup = '<div style="display: flex;">' . $button1 . $button2 . $button3 . $button4 . '</div>';
            return $buttonsGroup;
            }, 'last')
            ->edit('jenkel', function ($row) {
                return $row->jenkel == 'L' ? 'Laki-laki' : 'Perempuan';
            })
            ->addNumbering()
            ->hide('iduser')
            ->toJson();
    }

    public function formtambah()
    {
        $db = db_connect();
        $query = $db->query("SELECT CONCAT('PS', LPAD(IFNULL(MAX(SUBSTRING(id_pasien, 3)) + 1, 1), 4, '0')) AS next_number FROM pasien");
        $row = $query->getRow();
        $next_number = $row->next_number;
        $data = [
            'next_number' => $next_number,
        ];
        return view('pasien/formtambah', $data);
    }

    public function save()
    {
        if ($this->request->isAJAX()) {
            $id_pasien = $this->request->getPost('id_pasien');
            $nama = $this->request->getPost('nama');
            $alamat = $this->request->getPost('alamat');
            $nohp = $this->request->getPost('nohp');
            $jenkel = $this->request->getPost('jenkel');
            $tgllahir = $this->request->getPost('tgllahir');
            $foto = $this->request->getFile('cover');

            $rules = [
                'nama' => [
                    'label' => 'Nama Pasien',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],

                'alamat' => [
                    'label' => 'Alamat',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'nohp' => [
                    'label' => 'No HP',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'jenkel' => [
                    'label' => 'Jenkel',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'tgllahir' => [
                    'label' => 'Tanggal Lahir',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'cover' => [
                    'label' => 'Foto',
                    'rules' => 'mime_in[cover,image/jpg,image/jpeg,image/gif,image/png]|max_size[cover,4096]', 
                    'errors' => [
                        'mime_in' => 'File harus berformat jpg, jpeg, atau png',
                        'max_size' => 'Ukuran file maksimal adalah 4MB'
                    ]
                ],

            ];

            if (!$this->validate($rules)) {
                $errors = [];
                foreach ($rules as $field => $rule) {
                    $errors["error_$field"] = $this->validator->getError($field);
                }

                $json = [
                    'error' => $errors
                ];
            } else {
                if ($foto->isValid() && !$foto->hasMoved()) {
                    $newName = 'foto-' . date('Ymd') . '-' . $id_pasien . '.' . $foto->getClientExtension();
                    $foto->move('assets/img/pasien/', $newName);

                    $modelPasien = new ModelPasien();
                    $modelPasien->insert([
                        'id_pasien' => $id_pasien,
                        'nama' => $nama,
                        'alamat' => $alamat,
                        'nohp' => $nohp,
                        'jenkel' => $jenkel,
                        'tgllahir' => $tgllahir,
                        'foto' => $newName,
                    ]);

                    $json = [
                        'sukses' => 'Berhasil Simpan Data'
                    ];
                } else {
                    $json = [
                        'error' => ['foto' => $foto->getErrorString() . '(' . $foto->getError() . ')']
                    ];
                }
            }
            echo json_encode($json);
        }
    }

    public function delete()
    {
        if ($this->request->isAJAX()) {
            $id_pasien = $this->request->getPost('id_pasien');

            $model = new ModelPasien();
            $model->where('id_pasien', $id_pasien)->delete();

            $json = [
                'sukses' => 'Data Pasien Berhasil Dihapus'
            ];
            return $this->response->setJSON($json);
        }
    }

    public function formedit($id_pasien)
    {
        $model = new ModelPasien();
        $pasien = $model->find($id_pasien);

        if (!$pasien) {
            return redirect()->to('/pasien')->with('error', 'Data Pasien tidak ditemukan');
        }
        
        $user = null;
        if (!empty($pasien['iduser'])) {
            $userModel = new UserModel();
            $user = $userModel->find($pasien['iduser']);
        }
        
        $data = [
            'pasien' => $pasien,
            'user' => $user
        ];

        return view('pasien/formedit', $data);
    }

    public function updatedata($id_pasien)
    {
        if ($this->request->isAJAX()) {
            $id_pasien = $this->request->getPost('id_pasien');
            $nama = $this->request->getPost('nama');
            $alamat = $this->request->getPost('alamat');
            $nohp = $this->request->getPost('nohp');
            $jenkel = $this->request->getPost('jenkel');
            $tgllahir = $this->request->getPost('tgllahir');
            $foto = $this->request->getFile('cover');
            $password = $this->request->getPost('password');
            
            $rules = [
                'nama' => [
                    'label' => 'Nama Pasien',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],

                'alamat' => [
                    'label' => 'Alamat',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'nohp' => [
                    'label' => 'No HP',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'jenkel' => [
                    'label' => 'Jenkel',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'tgllahir' => [
                    'label' => 'Tanggal Lahir',
                    'rules' => 'required',
                    'errors' => [
                        'required' => '{field} tidak boleh kosong',
                    ]
                ],
                'cover' => [
                    'label' => 'Foto',
                    'rules' => 'mime_in[cover,image/jpg,image/jpeg,image/gif,image/png]|max_size[cover,4096]',
                    'errors' => [
                        'mime_in' => 'File harus berformat jpg, jpeg, atau png',
                        'max_size' => 'Ukuran file maksimal adalah 4MB'
                    ]
                ],
                'password' => [
                    'label' => 'Password',
                    'rules' => 'permit_empty|min_length[6]',
                    'errors' => [
                        'min_length' => 'Password minimal 6 karakter'
                    ]
                ]
            ];

            if (!$this->validate($rules)) {
                $errors = [];
                foreach ($rules as $field => $rule) {
                    $errors["error_$field"] = $this->validator->getError($field);
                }

                $json = [
                    'error' => $errors
                ];
            } else {
                $model = new ModelPasien();
                $dataPasien = $model->where('id_pasien', $id_pasien)->first();
                
                if ($foto->isValid() && !$foto->hasMoved()) {
                    $random = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
                    $newName = 'foto-' . date('Ymd') . '-' . $id_pasien . '.' . $random . '.' . $foto->getClientExtension();
                    $foto->move('assets/img/pasien/', $newName);

                    // Hapus foto lama jika ada
                    if (!empty($dataPasien['foto']) && file_exists('assets/img/pasien/' . $dataPasien['foto'])) {
                        unlink('assets/img/pasien/' . $dataPasien['foto']);
                    }

                    $dataUpdate = [
                        'nama' => $nama,
                        'alamat' => $alamat,
                        'nohp' => $nohp,
                        'jenkel' => $jenkel,
                        'tgllahir' => $tgllahir,
                        'foto' => $newName,
                    ];
                } else {
                    $dataUpdate = [
                        'nama' => $nama,
                        'alamat' => $alamat,
                        'nohp' => $nohp,
                        'jenkel' => $jenkel,
                        'tgllahir' => $tgllahir,
                    ];
                    
                    // Jika update tanpa mengubah foto, tetap gunakan foto yang ada (jika ada)
                    if (isset($dataPasien['foto'])) {
                        $dataUpdate['foto'] = $dataPasien['foto'];
                    }
                }
                
                $model->update($id_pasien, $dataUpdate);
                
                // Update password jika ada
                if (!empty($password) && !empty($dataPasien['iduser'])) {
                    $userModel = new \App\Models\UserModel();
                    $userModel->save([
                        'id' => $dataPasien['iduser'],
                        'password' => $password
                    ]);
                }
                
                $json = [
                    'sukses' => 'Data berhasil diupdate'
                ];
            }
            return $this->response->setJSON($json);
        }
    }
    public function detail($id_pasien)
    {
        $db = db_connect();
        $pasien = $db->table('pasien')->select('*')
        ->where('id_pasien', $id_pasien)->get()->getRowArray();

        if (!$pasien) {
            return redirect()->back()->with('error', 'Data pasien tidak ditemukan');
        }

        $data = [
            'pasien' => $pasien
        ];

        return view('pasien/detail', $data);
}

    public function createUser($id_pasien = null)
    {
        // Pastikan id_pasien tidak null
        if ($id_pasien === null) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'ID Pasien tidak ditemukan'
            ]);
        }
        
        $pasienModel = new ModelPasien();
        $userModel = new \App\Models\UserModel();
        $pasien = $pasienModel->find($id_pasien);
        
        if (!$pasien) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data pasien tidak ditemukan'
            ]);
        }
        
        // Validasi input
        $rules = [
            'username' => [
                'rules' => 'required|min_length[5]|is_unique[users.username]',
                'errors' => [
                    'required' => 'Username harus diisi',
                    'min_length' => 'Username minimal 5 karakter',
                    'is_unique' => 'Username sudah digunakan'
                ]
            ],
            'email' => [
                'rules' => 'required|valid_email|is_unique[users.email]',
                'errors' => [
                    'required' => 'Email harus diisi',
                    'valid_email' => 'Format email tidak valid',
                    'is_unique' => 'Email sudah digunakan'
                ]
            ],
            'password' => [
                'rules' => 'required|min_length[6]',
                'errors' => [
                    'required' => 'Password harus diisi',
                    'min_length' => 'Password minimal 6 karakter'
                ]
            ]
        ];
        
        if (!$this->validate($rules)) {
            return $this->response->setJSON([
                'status' => 'error',
                'errors' => $this->validator->getErrors()
            ]);
        }
        
        // Buat user baru
        $userData = [
            'username' => $this->request->getPost('username'),
            'email' => $this->request->getPost('email'),
            'password' => $this->request->getPost('password'),
            'role' => 'pasien',
            'status' => 'active'
        ];
        
        $userModel->insert($userData);
        $userId = $userModel->getInsertID();
        
        // Update data pasien dengan ID user baru
        $pasienModel->update($id_pasien, ['iduser' => $userId]);
        
        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Akun user untuk pasien berhasil dibuat'
        ]);
    }
    
    public function updatePassword($id_pasien = null)
    {
        // Pastikan id_pasien tidak null
        if ($id_pasien === null) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'ID Pasien tidak ditemukan'
            ]);
        }
        
        $pasienModel = new ModelPasien();
        $userModel = new \App\Models\UserModel();
        $pasien = $pasienModel->find($id_pasien);
        
        if (!$pasien) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Data pasien tidak ditemukan'
            ]);
        }
        
        if (!$pasien['iduser']) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Pasien belum memiliki akun user'
            ]);
        }
        
        // Validasi input
        $rules = [
            'password' => [
                'rules' => 'permit_empty|min_length[6]',
                'errors' => [
                    'min_length' => 'Password minimal 6 karakter'
                ]
            ]
        ];
        
        if (!$this->validate($rules)) {
            return $this->response->setJSON([
                'status' => 'error',
                'errors' => $this->validator->getErrors()
            ]);
        }
        
        $password = $this->request->getPost('password');
        
        // Jika password kosong, abaikan update password
        if (empty($password)) {
            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Tidak ada perubahan pada password'
            ]);
        }
        
        // Update password user
        $userData = [
            'id' => $pasien['iduser'],
            'password' => $password
        ];
        
        $userModel->save($userData);
        
        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Password berhasil diperbarui'
        ]);
    }
}
