<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CbtExamNameModel;

class CbtExamName extends BaseController
{
    protected $examNameModel;

    public function __construct()
    {
        $this->examNameModel = new CbtExamNameModel();
    }

    public function index()
    {
        $user = session()->get('user');
        $isAdmin = ($user['role_id'] ?? 0) == 1;

        $examNames = [];
        try {
            $examNames = $this->examNameModel->orderBy('id', 'DESC')->findAll();
        } catch (\Throwable $e) {
            log_message('error', '[CbtExamName] Error querying cbt_exam_names: ' . $e->getMessage());
        }

        $data = [
            'examNames' => $examNames,
            'title'     => 'Daftar Nama Ujian',
            'isAdmin'   => $isAdmin,
        ];

        return view('admin/cbt/exam_name/index', $data);
    }

    public function store()
    {
        $user = session()->get('user');
        $isAdmin = ($user['role_id'] ?? 0) == 1;
        if (!$isAdmin) {
            return redirect()->back()->with('error', 'Hanya admin yang diizinkan menambah nama ujian.');
        }

        $name = trim((string)$this->request->getPost('name'));

        if (empty($name)) {
            return redirect()->back()->with('error', 'Nama ujian tidak boleh kosong.');
        }

        $this->examNameModel->save(['name' => $name]);
        return redirect()->back()->with('success', 'Nama ujian berhasil ditambahkan.');
    }

    public function update($id)
    {
        $user = session()->get('user');
        $isAdmin = ($user['role_id'] ?? 0) == 1;
        if (!$isAdmin) {
            return redirect()->back()->with('error', 'Hanya admin yang diizinkan memperbarui nama ujian.');
        }

        $name = trim((string)$this->request->getPost('name'));
        if (empty($name)) {
            return redirect()->back()->with('error', 'Nama ujian tidak boleh kosong.');
        }

        $this->examNameModel->update($id, ['name' => $name]);
        return redirect()->back()->with('success', 'Nama ujian berhasil diperbarui.');
    }

    public function delete($id)
    {
        $user = session()->get('user');
        $isAdmin = ($user['role_id'] ?? 0) == 1;
        if (!$isAdmin) {
            return redirect()->back()->with('error', 'Hanya admin yang diizinkan menghapus nama ujian.');
        }

        $this->examNameModel->delete($id);
        return redirect()->back()->with('success', 'Nama ujian berhasil dihapus.');
    }
}
