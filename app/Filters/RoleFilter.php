<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class RoleFilter implements FilterInterface
{
    /**
     * Do whatever processing this filter needs to do.
     * By default it should not return anything during
     * normal execution. However, when an abnormal state
     * is found, it should return an instance of
     * CodeIgniter\HTTP\Response. If it does, script
     * execution will end and that Response will be
     * sent back to the client, allowing for error pages,
     * redirects, etc.
     *
     * @param RequestInterface $request
     * @param array|null       $arguments
     *
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // If no arguments, do nothing
        if (empty($arguments)) {
            return;
        }

        // Get current user role
        $userRole = session()->get('role');

        // Check if user's role is one of the allowed roles
        $isAllowed = false;
        foreach ($arguments as $role) {
            if ($userRole === $role) {
                $isAllowed = true;
                break;
            }
        }

        // If not allowed, redirect to dashboard with error message
        if (!$isAllowed) {
            return redirect()->to(site_url('admin'))->with('error', 'Anda tidak memiliki akses ke halaman tersebut.');
        }
    }

    /**
     * Allows After filters to inspect and modify the response
     * object as needed. This method does not allow any way
     * to stop execution of other after filters, short of
     * throwing an Exception or Error.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array|null        $arguments
     *
     * @return mixed
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Do nothing
    }
} 