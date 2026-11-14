<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CbtBankSoalModel;
use App\Models\CbtQuestionModel;
use Dompdf\Dompdf;

class CbtBankSoal extends BaseController
{
    protected $bankModel;
    protected $questionModel;

    public function __construct()
    {
        $this->bankModel = new CbtBankSoalModel();
        $this->questionModel = new CbtQuestionModel();
    }

    /**
     * Refresh CSRF token untuk AJAX requests
     */
    public function refreshCsrf()
    {
        return $this->response->setJSON([
            'token_name' => csrf_token(),
            'token_hash' => csrf_hash()
        ]);
    }

    public function index()
    {
        // Ambil data session dari key 'user'
        $user = session()->get('user');

        // Deteksi role
        $roleId = $user['role_id'] ?? null;
        $teacherId = $user['teacher_id'] ?? null;

        // ADMIN (role_id = 1) → lihat semua bank soal
        if ($roleId == 1) {
            $banks = $this->bankModel->getListWithCounts();
        }
        // GURU (role_id = 2) → hanya lihat bank soal miliknya
        elseif ($roleId == 2 && $teacherId) {
            $banks = $this->bankModel->getListWithCounts('teacher', (int) $teacherId);
        }
        // fallback (jaga-jaga)
        else {
            $banks = [];
        }

        // ✅ Tambahkan informasi ujian aktif untuk setiap bank soal
        $db = \Config\Database::connect();
        foreach ($banks as &$bank) {
            $bank['creator_name'] = !empty($bank['teacher_name'])
                ? $bank['teacher_name']
                : 'Admin';
            
            // Count active tests using this bank
            $bank['active_tests_count'] = $db->table('cbt_test_status')
                ->where('bank_id', $bank['id'])
                ->where('is_visible', 1)
                ->countAllResults();
            
            // Flag if bank is in use
            $bank['is_in_use'] = $bank['active_tests_count'] > 0;
        }

        // Ambil daftar mata pelajaran untuk modal tambah/edit bank soal
        $subjectModel = new \App\Models\SubjectModel();
        try {
            $subjects = $subjectModel->select('id, name')->orderBy('name', 'ASC')->findAll();
        } catch (\Throwable $e) {
            log_message('error', '[CbtBankSoal] Failed to fetch subjects: ' . $e->getMessage());
            $subjects = [];
        }

        return view('admin/cbt/banksoal/index', [
            'title'    => 'Daftar Bank Soal',
            'banks'    => $banks,
            'subjects' => $subjects,
        ]);
    }



    public function storeAjax()
    {
        if (!$this->request->isAJAX()) {
            return $this->response->setJSON(['success' => false, 'error' => 'Invalid request']);
        }

        $db = db_connect();
        $school = $db->table('school_profile')->select('level')->get()->getRow();

        $levelName = match ((int) ($school->level ?? 0)) {
            1 => 'SD',
            2 => 'SMP',
            default => 'N/A'
        };

        // Wajib: ambil session dari key 'user'
        $user = session()->get('user');

        // Guru = role_id 2
        $teacherId = ($user['role_id'] ?? 0) == 2
            ? ($user['teacher_id'] ?? null)
            : null;

        $subjectId = $this->request->getPost('subject_id');

        // Ambil nama pembuat
        if ($teacherId) {
            $creator = $db->table('teachers')->select('name')->where('id', $teacherId)->get()->getRow();
            $creatorName = $creator->name ?? 'Guru';
        } else {
            $creatorName = $user['fullname'] ?? 'Admin';
        }

        $data = [
            'code' => $this->request->getPost('code'),
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
            'level' => $levelName,
            'total_questions' => 0,
            'total_pg' => 0,
            'total_pg_kompleks' => 0,
            'total_esai' => 0,
            'option_count' => $this->request->getPost('option_count'),
            'is_active' => 0,
        ];

        try {
            $id = $this->bankModel->insert($data);
            if (!$id) {
                return $this->response->setJSON(['success' => false, 'error' => $this->bankModel->errors()]);
            }
        } catch (\mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return $this->response->setJSON(['success' => false, 'error' => 'Kode bank soal sudah digunakan.']);
            }
            return $this->response->setJSON(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }

        $subject = $db->table('subjects')->select('name')->where('id', $subjectId)->get()->getRow();

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Bank soal berhasil dibuat',
            'data' => array_merge($data, [
                'id' => $id,
                'subject_name' => $subject->name ?? '(Tidak diketahui)',
                'creator_name' => $creatorName,
            ])
        ]);
    }


    public function updateAjax()
    {
        if (!$this->request->isAJAX()) {
            return $this->response->setJSON(['success' => false, 'error' => 'Invalid request']);
        }

        $user = session()->get('user');
        $roleId = $user['role_id'] ?? null;
        $teacherId = $user['teacher_id'] ?? null;

        $id = $this->request->getPost('id');
        $bank = $this->bankModel->find($id);

        if (!$bank) {
            return $this->response->setJSON(['success' => false, 'error' => 'Bank soal tidak ditemukan']);
        }

        /* ==========================================================
         * 🔒 PROTEKSI AKSES EDIT:
         * Guru hanya boleh edit bank soal miliknya.
         * ========================================================== */
        if ($roleId == 2 && $bank['teacher_id'] != $teacherId) {
            return $this->response->setJSON([
                'success' => false,
                'error' => 'Anda tidak memiliki akses untuk mengedit bank soal ini.'
            ]);
        }

        /* ==========================================================
         * UPDATE DATA
         * ========================================================== */
        if (!$id) {
            return $this->response->setJSON(['success' => false, 'error' => 'ID tidak ditemukan']);
        }

        $data = [
            'code' => $this->request->getPost('code'),
            'subject_id' => $this->request->getPost('subject_id'),
            'option_count' => $this->request->getPost('option_count'),
        ];

        try {
            if (!$this->bankModel->update($id, $data)) {
                return $this->response->setJSON(['success' => false, 'error' => $this->bankModel->errors()]);
            }
        } catch (\mysqli_sql_exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return $this->response->setJSON(['success' => false, 'error' => 'Kode bank soal sudah digunakan']);
            }
            return $this->response->setJSON(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }

        // Ambil ulang data untuk mengirim ke frontend
        $bank = $this->bankModel
            ->select('cbt_question_banks.*, subjects.name AS subject_name')
            ->join('subjects', 'subjects.id = cbt_question_banks.subject_id', 'left')
            ->find($id);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Bank soal berhasil diperbarui',
            'data' => $bank,
        ]);
    }



    public function detail($id)
    {
        $bank = $this->bankModel
            ->select('cbt_question_banks.*, subjects.name AS subject_name')
            ->join('subjects', 'subjects.id = cbt_question_banks.subject_id', 'left')
            ->find($id);

        if (!$bank) {
            return redirect()->back()->with('error', 'Bank soal tidak ditemukan.');
        }

        $questions = $this->questionModel->where('bank_id', $id)->findAll();

        return view('admin/cbt/banksoal/detail', [
            'title' => 'Rincian Bank Soal',
            'bank' => $bank,
            'questions' => $questions
        ]);
    }

    public function copy($id)
    {
        $source = $this->bankModel->find($id);
        if (!$source)
            return redirect()->back()->with('error', 'Bank soal tidak ditemukan.');

        $newData = $source;
        unset($newData['id']);
        $newData['code'] = $source['code'] . '_COPY';
        $newData['is_active'] = 0;

        $newId = $this->bankModel->insert($newData);

        // Copy all questions
        $questions = $this->questionModel->where('bank_id', $id)->findAll();
        foreach ($questions as $q) {
            unset($q['id']);
            $q['bank_id'] = $newId;
            $this->questionModel->insert($q);
        }

        return redirect()->to('/admin/cbt/banksoal')->with('success', 'Bank soal berhasil disalin.');
    }

    public function print($id)
    {
        $bank = $this->bankModel->getWithSubjectTeacher($id);
        if (!$bank) {
            return redirect()->back()->with('error', 'Bank soal tidak ditemukan.');
        }

        $questionsRaw = $this->questionModel->where('bank_id', $id)->findAll();
        $questions = [];

        foreach ($questionsRaw as $q) {
            $parsed = $this->questionModel->parseRawQuestion($q['raw_text'] ?? $q['question_text']);
            $q['question_text_clean'] = $parsed['question'];
            $questions[] = $q;
        }

        $html = view('admin/cbt/banksoal/print', compact('bank', 'questions'));

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream('bank-soal-' . $id . '.pdf');
    }

    public function edit_Soal($bankId, $questionId)
    {
        $bank = $this->bankModel->find($bankId);
        $soal = $this->questionModel->find($questionId);

        if (!$bank || !$soal) {
            throw new \CodeIgniter\Exceptions\PageNotFoundException('Soal tidak ditemukan');
        }

        // Jika raw_text kosong namun soal memiliki opsi, rekonstruksi format lengkap
        if (empty($soal['raw_text']) && !empty($soal['option_a'])) {
            $raw = '<p>' . ($soal['question_text'] ?? '') . '</p>';
            foreach (['a', 'b', 'c', 'd', 'e'] as $opt) {
                $val = $soal['option_' . $opt] ?? '';
                if ($val !== '' && $val !== null) {
                    $raw .= '<p>' . strtoupper($opt) . ': ' . $val . '</p>';
                }
            }
            if (!empty($soal['correct_option'])) {
                $raw .= '<p>Kunci: ' . $soal['correct_option'] . '</p>';
            }
            $soal['raw_text'] = $raw;
        }

        $rawContent = !empty($soal['raw_text']) ? $soal['raw_text'] : ($soal['question_text'] ?? '');
        $soal['raw_text_normalized'] = $this->normalizeRawTextForEditor($rawContent);

        return view('admin/cbt/banksoal/edit_soal', [
            'title' => 'Edit Soal',
            'bank' => $bank,
            'soal' => $soal,
        ]);
    }

    public function updateSoal($bankId, $questionId)
    {
        $rawHtml = $this->request->getPost('raw_text');

        if (empty($rawHtml)) {
            return $this->response->setJSON(['error' => 'Soal tidak boleh kosong.']);
        }

        // Process embedded images
        $cleanHtml = $this->processEmbeddedImages($rawHtml);

        // Parse HTML to extract question data
        $parsedQuestions = $this->extractQuestionsFromHtml($cleanHtml);

        if (empty($parsedQuestions)) {
            return $this->response->setJSON(['error' => 'Tidak ada soal valid yang ditemukan dalam format yang diberikan.']);
        }

        // Use first parsed question (since we're editing one question at a time)
        $q = $parsedQuestions[0];

        // Prepare data for update
        $data = [
            'question_text' => trim($q['question']),
            'option_a' => $q['options']['A'] ?? null,
            'option_b' => $q['options']['B'] ?? null,
            'option_c' => $q['options']['C'] ?? null,
            'option_d' => $q['options']['D'] ?? null,
            'option_e' => $q['options']['E'] ?? null,
            'correct_option' => $q['key'] ?? null,
            'question_type' => $q['type'],
            'score' => $q['type'] === 'esai' ? 0 : 1,
            'media_image' => json_encode($q['images'] ?? []),
            'has_image' => !empty($q['images']) ? 1 : 0,
            'raw_text' => $rawHtml,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // 🔒 SECURITY: Handle media (audio/video) file uploads with validation
        helper('security'); // Load security helper
        
        $audioFiles = ['audio_file', 'audio_a', 'audio_b', 'audio_c', 'audio_d', 'audio_e'];
        $allowedAudioTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg', 'audio/mp4'];
        $maxAudioSize = 10 * 1024 * 1024; // 10MB
        $maxVideoSize = 25 * 1024 * 1024; // 25MB

        $existingSoal = $this->questionModel->find($questionId);
        
        foreach ($audioFiles as $field) {
            $file = $this->request->getFile($field);
            if ($file && $file->isValid() && !$file->hasMoved()) {
                $mimeType = $file->getMimeType();
                $extension = strtolower($file->getExtension());

                if ($field === 'audio_file') {
                    // Unified Media Input: Deteksi apakah file Audio atau Video
                    $isVideo = str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'webm']);
                    $isAudio = str_starts_with($mimeType, 'audio/') || in_array($extension, ['mp3', 'wav', 'ogg']);

                    if (!$isVideo && !$isAudio) {
                        return $this->response->setJSON([
                            'success' => false,
                            'message' => 'File media stimulus harus berupa Audio (MP3, WAV, OGG) atau Video (MP4, WebM)'
                        ]);
                    }

                    $maxSize = $isVideo ? $maxVideoSize : $maxAudioSize;
                    if (!validate_file_size($file, $maxSize)) {
                        return $this->response->setJSON([
                            'success' => false,
                            'message' => 'Ukuran file ' . ($isVideo ? 'video maksimal 25MB' : 'audio maksimal 10MB')
                        ]);
                    }

                    if ($isVideo) {
                        $targetDir = FCPATH . 'uploads/video';
                        if (!is_dir($targetDir)) {
                            @mkdir($targetDir, 0755, true);
                        }
                        $newName = uniqid('video_', true) . '.' . $extension;
                        $file->move($targetDir, $newName);

                        // Hapus file media lama jika ada
                        if (!empty($existingSoal['media_audio'])) {
                            $oldAudio = FCPATH . 'uploads/audio/' . $existingSoal['media_audio'];
                            if (file_exists($oldAudio)) @unlink($oldAudio);
                        }
                        if (!empty($existingSoal['media_video'])) {
                            $oldVideo = FCPATH . 'uploads/video/' . $existingSoal['media_video'];
                            if (file_exists($oldVideo)) @unlink($oldVideo);
                        }

                        $data['media_video'] = $newName;
                        $data['media_audio'] = null;
                        $data['has_audio'] = 0;
                    } else {
                        $targetDir = FCPATH . 'uploads/audio';
                        if (!is_dir($targetDir)) {
                            @mkdir($targetDir, 0755, true);
                        }
                        $newName = uniqid('audio_', true) . '.' . $extension;
                        $file->move($targetDir, $newName);

                        // Hapus file media lama jika ada
                        if (!empty($existingSoal['media_video'])) {
                            $oldVideo = FCPATH . 'uploads/video/' . $existingSoal['media_video'];
                            if (file_exists($oldVideo)) @unlink($oldVideo);
                        }
                        if (!empty($existingSoal['media_audio'])) {
                            $oldAudio = FCPATH . 'uploads/audio/' . $existingSoal['media_audio'];
                            if (file_exists($oldAudio)) @unlink($oldAudio);
                        }

                        $data['media_audio'] = $newName;
                        $data['media_video'] = null;
                        $data['has_audio'] = 1;
                    }

                    log_security_event('file_upload', 'Media stimulus file uploaded', [
                        'type' => $isVideo ? 'video' : 'audio',
                        'filename' => $newName,
                        'size' => $file->getSize(),
                        'user_id' => session()->get('user')['id'] ?? 'unknown'
                    ]);
                } else {
                    // 🔒 Opsi Audio (A s/d E)
                    if (!validate_file_type($file, $allowedAudioTypes)) {
                        return $this->response->setJSON([
                            'success' => false,
                            'message' => 'File audio opsi harus berformat MP3, WAV, atau OGG'
                        ]);
                    }
                    
                    if (!validate_file_size($file, $maxAudioSize)) {
                        return $this->response->setJSON([
                            'success' => false,
                            'message' => 'Ukuran file audio maksimal 10MB'
                        ]);
                    }
                    
                    $newName = uniqid('audio_', true) . '.' . $extension;
                    $targetDir = FCPATH . 'uploads/audio';
                    if (!is_dir($targetDir)) {
                        @mkdir($targetDir, 0755, true);
                    }
                    $file->move($targetDir, $newName);
                    $data[$field] = $newName;

                    log_security_event('file_upload', 'Audio option file uploaded', [
                        'field' => $field,
                        'filename' => $newName,
                        'size' => $file->getSize(),
                        'user_id' => session()->get('user')['id'] ?? 'unknown'
                    ]);
                }
            }
        }

        // Update the question
        $this->questionModel->update($questionId, $data);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Soal berhasil diperbarui.'
        ]);
    }

    public function deleteAudio($questionId, $type = 'main')
    {
        $soal = $this->questionModel->find($questionId);
        if (!$soal)
            return $this->response->setJSON(['success' => false, 'message' => 'Soal tidak ditemukan']);

        if ($type === 'main') {
            if (!empty($soal['media_audio'])) {
                $path = FCPATH . 'uploads/audio/' . $soal['media_audio'];
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            if (!empty($soal['media_video'])) {
                $path = FCPATH . 'uploads/video/' . $soal['media_video'];
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            $update = [
                'media_audio' => null,
                'media_video' => null,
                'has_audio' => 0,
            ];
            $this->questionModel->update($questionId, $update);
            return $this->response->setJSON(['success' => true, 'message' => 'Media stimulus berhasil dihapus.']);
        }

        $field = 'audio_' . $type;
        if (!empty($soal[$field])) {
            $path = FCPATH . 'uploads/audio/' . $soal[$field];
            if (file_exists($path)) {
                @unlink($path);
            }
            $this->questionModel->update($questionId, [$field => null]);
        }
        return $this->response->setJSON(['success' => true, 'message' => 'Audio opsi berhasil dihapus.']);
    }

    public function deleteQuestionAjax()
    {
        $id = $this->request->getPost('id');
        $bankId = $this->request->getPost('bank_id');

        if (empty($id) || empty($bankId)) {
            return $this->response->setJSON(['error' => 'Data tidak lengkap.']);
        }

        // 🔒 Pastikan soal memang milik bank soal ini
        $question = $this->questionModel
            ->where('id', $id)
            ->where('bank_id', $bankId)
            ->first();

        if (!$question) {
            return $this->response->setJSON(['error' => 'Soal tidak ditemukan atau tidak sesuai bank.']);
        }

        // 🧹 Hapus soal
        $this->questionModel->delete($id);

        // 🔁 Update ringkasan bank soal - Optimize: Single query with GROUP BY
        $db = \Config\Database::connect();
        $counts = $db->table('cbt_questions')
            ->select('question_type, COUNT(*) as count')
            ->where('bank_id', $bankId)
            ->groupBy('question_type')
            ->get()
            ->getResultArray();
        
        // Build count map
        $countMap = [];
        $totalQuestions = 0;
        foreach ($counts as $row) {
            $countMap[$row['question_type']] = (int) $row['count'];
            $totalQuestions += (int) $row['count'];
        }

        $this->bankModel->update($bankId, [
            'total_questions' => $totalQuestions,
            'total_pg' => $countMap['pg'] ?? 0,
            'total_pg_kompleks' => $countMap['pg_kompleks'] ?? 0,
            'total_esai' => $countMap['esai'] ?? 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Soal berhasil dihapus.',
            csrf_token() => csrf_hash()
        ]);
    }

    /**
     * 🔄 Hitung ulang statistik bank setelah hapus soal
     */
    private function recountBankStats($bankId)
    {
        $questions = $this->questionModel->where('bank_id', $bankId)->findAll();

        $total = count($questions);
        $pg = count(array_filter($questions, fn($q) => $q['question_type'] === 'pg'));
        $pgk = count(array_filter($questions, fn($q) => $q['question_type'] === 'pg_kompleks'));
        $bs = count(array_filter($questions, fn($q) => $q['question_type'] === 'benar_salah'));
        $esai = count(array_filter($questions, fn($q) => $q['question_type'] === 'esai'));

        $this->bankModel->update($bankId, [
            'total_questions' => $total,
            'total_pg' => $pg,
            'total_pg_kompleks' => $pgk,
            'total_bs' => $bs,
            'total_esai' => $esai,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }


    public function updateQuestion($id)
    {
        $db = \Config\Database::connect();
        $data = $this->request->getPost();
        $update = [
            'question_text' => $data['question_text'],
            'question_type' => $data['question_type'],
            'score' => $data['score'],
            'option_a' => $data['option_a'] ?? null,
            'option_b' => $data['option_b'] ?? null,
            'option_c' => $data['option_c'] ?? null,
            'option_d' => $data['option_d'] ?? null,
            'option_e' => $data['option_e'] ?? null,
            'correct_option' => $data['correct_option'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $db->table('cbt_questions')->where('id', $id)->update($update);
        return $this->response->setJSON(['success' => true, 'message' => 'Soal berhasil diperbarui.']);
    }


    public function delete($id)
    {
        // ✅ VALIDASI: Cek apakah bank soal sedang digunakan dalam ujian aktif
        $db = \Config\Database::connect();
        
        // Check if bank is used in any active test
        $activeTests = $db->table('cbt_test_status')
            ->where('bank_id', $id)
            ->where('is_visible', 1)
            ->countAllResults();
        
        if ($activeTests > 0) {
            return redirect()->back()->with('error', 
                'Bank soal tidak dapat dihapus karena sedang digunakan dalam ' . $activeTests . ' ujian aktif. ' .
                'Nonaktifkan ujian terlebih dahulu sebelum menghapus bank soal.'
            );
        }
        
        // Check if bank is used in any test (even inactive ones) - optional warning
        $allTests = $db->table('cbt_test_status')
            ->where('bank_id', $id)
            ->countAllResults();
        
        if ($allTests > 0) {
            // Bank is used in inactive tests - allow deletion but with warning
            log_message('info', "[BANK_SOAL] Deleting bank $id that is used in $allTests inactive tests");
        }
        
        // Proceed with deletion
        $this->questionModel->where('bank_id', $id)->delete();
        $this->bankModel->delete($id);
        
        return redirect()->to('/admin/cbt/banksoal')->with('success', 'Bank soal berhasil dihapus.');
    }


    public function bulkDelete()
    {
        $ids = $this->request->getPost('ids');
        if (!$ids)
            return redirect()->back()->with('error', 'Tidak ada data yang dipilih.');

        // ✅ VALIDASI: Cek apakah ada bank soal yang sedang digunakan dalam ujian aktif
        $db = \Config\Database::connect();
        
        $activeBanks = [];
        $deletedCount = 0;
        $skippedCount = 0;
        
        foreach ($ids as $id) {
            // Check if bank is used in any active test
            $activeTests = $db->table('cbt_test_status')
                ->where('bank_id', $id)
                ->where('is_visible', 1)
                ->countAllResults();
            
            if ($activeTests > 0) {
                // Skip this bank - it's being used
                $bank = $this->bankModel->find($id);
                $bankName = $bank ? $bank['code'] : "ID $id";
                $activeBanks[] = "$bankName ($activeTests ujian aktif)";
                $skippedCount++;
            } else {
                // Safe to delete
                $this->questionModel->where('bank_id', $id)->delete();
                $this->bankModel->delete($id);
                $deletedCount++;
            }
        }
        
        // Build response message
        $message = '';
        if ($deletedCount > 0) {
            $message .= "$deletedCount bank soal berhasil dihapus. ";
        }
        
        if ($skippedCount > 0) {
            $message .= "$skippedCount bank soal tidak dapat dihapus karena sedang digunakan: " . implode(', ', $activeBanks);
            return redirect()->back()->with('warning', $message);
        }
        
        return redirect()->back()->with('success', $message ?: 'Tidak ada bank soal yang dihapus.');
    }


    public function toggle($id)
    {
        $bank = $this->bankModel->find($id);
        if (!$bank)
            return redirect()->back()->with('error', 'Data tidak ditemukan.');

        $this->bankModel->update($id, ['is_active' => !$bank['is_active']]);
        return redirect()->back()->with('success', 'Status berhasil diperbarui.');
    }


    public function deleteQuestion($bankId, $questionId)
    {
        $this->questionModel->delete($questionId);
        return redirect()->to("/admin/cbt/banksoal/detail/$bankId")->with('success', 'Soal berhasil dihapus.');
    }

    public function addQuestionAjax()
    {
        if (!$this->request->isAJAX())
            return $this->response->setJSON(['success' => false, 'error' => 'Invalid request']);

        $data = [
            'bank_id' => $this->request->getPost('bank_id'),
            'question_text' => $this->request->getPost('question_text'),
            'question_type' => $this->request->getPost('question_type'),
            'score' => $this->request->getPost('score')
        ];

        $this->questionModel->insert($data);
        $this->updateBankTotals($data['bank_id']);

        return $this->response->setJSON(['success' => true, 'message' => 'Soal berhasil ditambahkan']);
    }

    public function updateQuestionAjax()
    {
        if (!$this->request->isAJAX())
            return $this->response->setJSON(['success' => false, 'error' => 'Invalid request']);

        $id = $this->request->getPost('id');
        $bankId = $this->request->getPost('bank_id');

        $data = [
            'question_text' => $this->request->getPost('question_text'),
            'question_type' => $this->request->getPost('question_type'),
            'score' => $this->request->getPost('score')
        ];

        $this->questionModel->update($id, $data);
        $this->updateBankTotals($bankId);

        return $this->response->setJSON(['success' => true, 'message' => 'Soal berhasil diperbarui']);
    }

    private function updateBankTotals($bankId)
    {
        // Optimize: Single query with GROUP BY instead of 5 separate queries
        $db = \Config\Database::connect();
        $counts = $db->table('cbt_questions')
            ->select('question_type, COUNT(*) as count')
            ->where('bank_id', $bankId)
            ->groupBy('question_type')
            ->get()
            ->getResultArray();
        
        // Build count map
        $countMap = [];
        $total = 0;
        foreach ($counts as $row) {
            $countMap[$row['question_type']] = (int) $row['count'];
            $total += (int) $row['count'];
        }
        
        $this->bankModel->update($bankId, [
            'total_questions' => $total,
            'total_pg' => $countMap['pg'] ?? 0,
            'total_pg_kompleks' => $countMap['pg_kompleks'] ?? 0,
            'total_bs' => $countMap['benar_salah'] ?? 0,
            'total_esai' => $countMap['esai'] ?? 0
        ]);
    }

    public function tambahSoal($bankId)
    {
        $bank = $this->bankModel
            ->select('cbt_question_banks.*, subjects.name as subject_name')
            ->join('subjects', 'subjects.id = cbt_question_banks.subject_id', 'left')
            ->where('cbt_question_banks.id', $bankId)
            ->first();

        if (!$bank)
            return redirect()->back()->with('error', 'Bank soal tidak ditemukan.');

        return view('admin/cbt/banksoal/tambah_soal', [
            'title' => 'Tambah Soal - ' . $bank['code'],
            'bank' => $bank
        ]);
    }

    public function parseSoal()
    {
        if (!$this->request->isAJAX())
            return $this->response->setJSON(['success' => false, 'error' => 'Invalid request']);

        $rawText = $this->request->getPost('text');
        $bankId = $this->request->getPost('bank_id');

        if (!$rawText) {
            return $this->response->setJSON(['success' => false, 'error' => 'Teks soal kosong.']);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($rawText));
        $parsed = [];
        $current = [];
        $type = 'pg';
        $optionStarted = false; // Flag untuk menandai opsi sudah mulai

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '')
                continue;

            // Deteksi awal soal - reset flag opsi
            if (preg_match('/^Soal:\s*(\d+)\)(.*)$/i', $line, $m)) {
                if (!empty($current))
                    $parsed[] = $current;
                
                $current = [
                    'nomor' => trim($m[1]),
                    'text' => trim($m[2]), // Bisa kosong jika teks di baris berikutnya
                    'options' => [],
                    'answer' => '',
                    'type' => 'pg'
                ];
                $optionStarted = false; // Reset flag untuk soal baru
                continue;
            }

            // Deteksi kunci jawaban (harus sebelum deteksi opsi)
            if (preg_match('/^Kunci:\s*(.*)$/i', $line, $m)) {
                $key = strtoupper(trim($m[1]));
                $current['answer'] = $key;
                $current['type'] = ($key === 'ESAI') ? 'esai' : 'pg';
                continue;
            }

            // Deteksi opsi dalam satu baris (A:... B:... C:... D:...)
            if (preg_match_all('/([A-E])\s*[:.)]\s*([^A-E:]+?)(?=\s+[A-E]\s*[:.]|$)/iu', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $optKey = strtoupper($match[1]);
                    $optText = trim($match[2]);
                    $current['options'][$optKey] = $optText;
                }
                $optionStarted = true;
                continue;
            }

            // Deteksi opsi jawaban per baris (A: ..., B: ..., dst)
            if (preg_match('/^[A-Ea-e]\s*[:.)]\s*(.*)$/u', $line, $m)) {
                $optKey = strtoupper(substr($line, 0, 1));
                $optText = trim($m[1]);
                $current['options'][$optKey] = $optText;
                $optionStarted = true; // Tandai bahwa opsi sudah mulai
                continue;
            }

            // Jika bukan opsi atau kunci, dan opsi belum mulai, tambahkan ke teks soal
            if (!empty($current) && !$optionStarted) {
                if (!empty($current['text'])) {
                    $current['text'] .= ' ' . $line;
                } else {
                    $current['text'] = $line;
                }
            }
        }

        if (!empty($current))
            $parsed[] = $current;

        if (empty($parsed)) {
            return $this->response->setJSON(['success' => false, 'error' => 'Format tidak dikenali. Pastikan format mengikuti contoh.']);
        }

        return $this->response->setJSON([
            'success' => true,
            'count' => count($parsed),
            'preview' => $parsed
        ]);
    }

    /**
     * Simpan hasil parsing dari CKEditor ke database
     */
    public function saveParsedSoal($bankId)
    {
        $rawHtml = $this->request->getPost('raw_text');
        if (empty($rawHtml)) {
            return $this->response->setJSON(['error' => 'Tidak ada data soal yang dikirim.']);
        }

        // 🔧 Simpan naskah mentah ke bank soal
        $this->bankModel->update($bankId, [
            'raw_text' => $rawHtml,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // 🧹 Bersihkan & proses gambar
        $cleanHtml = $this->processEmbeddedImages($rawHtml);

        // 🧩 Parsing HTML menjadi array soal
        $parsedQuestions = $this->extractQuestionsFromHtml($cleanHtml);
        if (empty($parsedQuestions)) {
            return $this->response->setJSON(['error' => 'Tidak ada soal valid yang ditemukan.']);
        }

        $dataBatch = [];
        $pgCount = 0;
        $pgkCount = 0;
        $bsCount = 0;
        $esaiCount = 0;

        foreach ($parsedQuestions as $q) {
            $dataBatch[] = [
                'bank_id' => $bankId,
                'question_text' => trim($q['question']),
                'option_a' => $q['options']['A'] ?? null,
                'option_b' => $q['options']['B'] ?? null,
                'option_c' => $q['options']['C'] ?? null,
                'option_d' => $q['options']['D'] ?? null,
                'option_e' => $q['options']['E'] ?? null,
                'correct_option' => $q['key'] ?? null,
                'question_type' => $q['type'],
                'essay_answer' => $q['type'] === 'esai' ? null : '',
                'score' => $q['type'] === 'esai' ? 0 : 1,
                'media_image' => json_encode($q['images'] ?? []),
                'has_image' => !empty($q['images']) ? 1 : 0,
                'has_audio' => 0,
                'raw_text' => $q['raw_html'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($q['type'] === 'pg')
                $pgCount++;
            elseif ($q['type'] === 'pg_kompleks')
                $pgkCount++;
            elseif ($q['type'] === 'benar_salah')
                $bsCount++;
            else
                $esaiCount++;
        }

        // 🚀 Insert batch
        $this->questionModel->insertBatch($dataBatch);

        // 🔄 Update ringkasan bank
        $this->bankModel->update($bankId, [
            'total_questions' => $pgCount + $pgkCount + $bsCount + $esaiCount,
            'total_pg' => $pgCount,
            'total_pg_kompleks' => $pgkCount,
            'total_bs' => $bsCount,
            'total_esai' => $esaiCount,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => "Berhasil menyimpan {$pgCount} soal PG, {$bsCount} soal BS, dan {$esaiCount} soal esai."
        ]);
    }


    private function extractQuestionsFromHtml($html)
    {
        // 🔹 Pecah berdasarkan penanda "Soal: n)" dengan boundary yang jelas
        // Menggunakan lookahead untuk memastikan tidak memotong angka desimal
        $blocks = preg_split('/Soal:\s*(\d+)\)(?!\d)/i', $html);
        $questions = [];

        foreach ($blocks as $b) {
            $b = trim($b);
            if (strlen(strip_tags($b)) < 10)
                continue;

            $rawHtmlBlock = $b; // simpan HTML utuh blok ini

            // 🔹 Normalisasi literal \n dan baris
            $b = str_replace(["\\r\\n", "\\r", "\\n"], "\n", $b);
            $normalized = preg_replace('/<p[^>]*>/i', "\n", $b);
            $normalized = preg_replace('/<br[^>]*>/i', "\n", $normalized);

            // 🔹 Simpan dulu <img> agar tidak hilang
            preg_match_all('/<img[^>]+>/i', $normalized, $imgTags);
            $imgList = $imgTags[0] ?? [];
            $tmp = preg_replace('/<img[^>]+>/i', '[[IMG]]', $normalized);

            // 🔹 Bersihkan HTML lain (tapi izinkan tag formatting dasar)
            $clean = strip_tags($tmp, '<b><i><u><strong><em><s><sub><sup>');
            $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $clean = str_replace(["\r", "\xc2\xa0", "&nbsp;"], ' ', $clean);

            // 🔹 Kembalikan tag <img> ke tempat semula
            foreach ($imgList as $tag) {
                $clean = preg_replace('/\[\[IMG\]\]/', $tag, $clean, 1);
            }

            $lines = array_values(array_filter(array_map('trim', explode("\n", $clean))));
            $questionLines = [];
            $options = [];
            $key = null;
            $type = 'pg';
            $optionStarted = false; // Flag untuk menandai opsi sudah mulai

            foreach ($lines as $line) {
                // Deteksi opsi jawaban - harus di awal baris dengan format ketat
                if (preg_match('/^([A-E])\s*[:.)]\s*(.+)$/iu', $line, $m)) {
                    // Opsi (bisa teks atau <img>)
                    $options[strtoupper($m[1])] = trim($m[2]);
                    $optionStarted = true; // Tandai opsi sudah mulai
                } elseif (preg_match('/^Tipe\s*[:.)]?\s*(bs|benar\s*salah|benar\/salah)/i', $line, $m)) {
                    $type = 'benar_salah';
                } elseif (preg_match('/^Kunci\s*[:.)]?\s*(esai|.*)/i', $line, $m)) {
                    $rawKey = trim($m[1]);
                    if (strtolower($rawKey) === 'esai') {
                        $type = 'esai';
                    } elseif ($type === 'benar_salah') {
                        // For Benar/Salah, we preserve the sequence exactly (e.g., B,S,B)
                        // Normalize: remove spaces, keep only B and S, then comma-separate if not already
                        $rawKey = strtoupper(preg_replace('/\s+/', '', $rawKey));
                        // If it contains commas, we trust the manual formatting
                        if (strpos($rawKey, ',') !== false) {
                            $key = $rawKey;
                        } else {
                            // Otherwise, split every character
                            $chars = str_split($rawKey);
                            $key = implode(',', $chars);
                        }
                    } else {
                        // Extract multiple keys (e.g., "A, B, C" or "A B C")
                        preg_match_all('/[A-E]/i', $rawKey, $keyMatches);
                        if (!empty($keyMatches[0])) {
                            $uniqueKeys = array_unique(array_map('strtoupper', $keyMatches[0]));
                            sort($uniqueKeys);
                            $key = implode(',', $uniqueKeys);
                            if (count($uniqueKeys) > 1) {
                                $type = 'pg_kompleks';
                            }
                        }
                    }
                } else {
                    // Hanya tambahkan ke teks soal jika opsi belum mulai
                    if (!$optionStarted) {
                        $questionLines[] = $line;
                    }
                }
            }

            // 🔹 Gabungkan teks pertanyaan
            $questionText = trim(preg_replace('/\s+/', ' ', implode(' ', $questionLines)));

            // 🔹 Deteksi otomatis esai (jika tidak ada opsi dan bukan BS)
            if (empty($options) && $type !== 'benar_salah') {
                $type = 'esai';
            }

            // 🔹 Kumpulkan URL gambar (opsional)
            preg_match_all('/<img[^>]+src="([^">]+)"/i', $b, $imgMatches);
            $images = $imgMatches[1] ?? [];

            $questions[] = [
                'question' => $questionText,
                'options' => $options,
                'key' => $key,
                'type' => $type,
                'images' => $images,
                'raw_html' => $rawHtmlBlock,
            ];
        }

        return $questions;
    }

    /**
     * Normalisasi teks soal mentah untuk editor CKEditor:
     * - Mengubah literal string '\r\n', '\n', '\r' menjadi line break (<br>)
     * - Melindungi formula matematika LaTeX ($...$ dan \(...\)) agar tidak terkorupsi
     * - Memastikan baris baru diterapkan secara visual di editor tanpa memunculkan karakter \n
     */
    private function normalizeRawTextForEditor($text)
    {
        if (!$text) {
            return '';
        }

        // Amankan formula LaTeX ($...$, $$...$$, \(...\), \[...\]) agar backslash tidak terganggu
        $mathBlocks = [];
        $text = preg_replace_callback('/(\$\$[\s\S]*?\$\$|\$[^\$]+?\$|\\\\\([\s\S]*?\\\\\)|\x5C\[[\s\S]*?\x5C\])/u', function ($m) use (&$mathBlocks) {
            $idx = count($mathBlocks);
            $mathBlocks[] = $m[0];
            return "___MATH_PLACEHOLDER_{$idx}___";
        }, $text);

        // 1. Ubah literal string \r\n, \r, \n menjadi karakter newline asli
        $text = str_replace(["\\r\\n", "\\r", "\\n"], "\n", $text);

        // 2. Jika teks murni (plain text), ubah newline menjadi <br>
        if (strip_tags($text) === $text) {
            $text = nl2br($text);
        } else {
            // Jika sudah mengandung tag HTML, ubah newline di dalam konten/tag menjadi <br>
            // (hindari menambah <br> di antara penutup dan pembuka tag blok: </p>\n<p>)
            $text = preg_replace('/(?<!>)\r?\n(?!<)/u', '<br>', $text);
        }

        // 3. Kembalikan blok LaTeX
        foreach ($mathBlocks as $idx => $math) {
            $text = str_replace("___MATH_PLACEHOLDER_{$idx}___", $math, $text);
        }

        return $text;
    }

    /**
     * ⚙️ Proses semua gambar base64 atau URL dari CKEditor
     * - Simpan ke /uploads/soal_images/
     * - Deteksi duplikat berdasarkan hash
     * - Auto resize gambar > 1MB
     * - Return HTML dengan src baru berbasis base_url()
     */
    private function processEmbeddedImages($html)
    {
        $uploadDir = FCPATH . 'uploads/soal_images/';
        $baseUrl = base_url('uploads/soal_images/');
        $hashCache = [];

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        preg_match_all('/<img[^>]+src="([^">]+)"/i', $html, $matches);
        $srcList = $matches[1] ?? [];

        foreach ($srcList as $src) {
            $newUrl = '';

            // 🧩 CASE 1: base64 (CKEditor paste)
            if (strpos($src, 'data:image') === 0) {
                if (preg_match('/data:image\/(\w+);base64,/', $src, $typeMatch)) {
                    $ext = strtolower($typeMatch[1]);
                } else {
                    $ext = 'png';
                }

                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (!in_array($ext, $allowed))
                    $ext = 'png';

                $data = explode(',', $src);
                $decoded = base64_decode(end($data));
                if (!$decoded)
                    continue;

                $hash = sha1($decoded);
                if (isset($hashCache[$hash])) {
                    $html = str_replace($src, $hashCache[$hash], $html);
                    continue;
                }

                $fileName = 'img_' . substr($hash, 0, 10) . '.' . $ext;
                $filePath = $uploadDir . $fileName;

                file_put_contents($filePath, $decoded);

                // 🧠 Auto resize jika > 1MB
                if (filesize($filePath) > 1024 * 1024) {
                    $this->resizeImage($filePath, $ext, 1280); // max width 1280px
                }

                $newUrl = $baseUrl . $fileName;
                $hashCache[$hash] = $newUrl;
                $html = str_replace($src, $newUrl, $html);
            }

            // 🌍 CASE 2: URL eksternal
            elseif (preg_match('/^https?:\/\//i', $src)) {
                $fileName = 'img_' . uniqid() . '.png';
                $filePath = $uploadDir . $fileName;

                try {
                    $imgData = @file_get_contents($src, false, stream_context_create([
                        'http' => ['timeout' => 5]
                    ]));

                    if ($imgData !== false) {
                        file_put_contents($filePath, $imgData);

                        if (filesize($filePath) > 1024 * 1024) {
                            $this->resizeImage($filePath, 'png', 1280);
                        }

                        $newUrl = $baseUrl . $fileName;
                        $html = str_replace($src, $newUrl, $html);
                    }
                } catch (\Throwable $e) {
                    log_message('error', 'Gagal ambil gambar eksternal: ' . $e->getMessage());
                }
            }
        }

        return $html;
    }

    /**
     * 🖼️ Resize gambar besar agar tidak memberatkan sistem
     * Gunakan GD library bawaan PHP (tidak perlu ekstensi tambahan)
     */
    private function resizeImage(string $path, string $ext, int $maxWidth = 1280): bool
    {
        if (!file_exists($path))
            return false;

        [$width, $height] = getimagesize($path);
        if ($width <= $maxWidth)
            return true; // tidak perlu resize

        $ratio = $height / $width;
        $newWidth = $maxWidth;
        $newHeight = (int) ($newWidth * $ratio);

        switch (strtolower($ext)) {
            case 'jpg':
            case 'jpeg':
                $srcImage = imagecreatefromjpeg($path);
                break;
            case 'png':
                $srcImage = imagecreatefrompng($path);
                break;
            case 'gif':
                $srcImage = imagecreatefromgif($path);
                break;
            case 'webp':
                $srcImage = imagecreatefromwebp($path);
                break;
            default:
                return false;
        }

        $dstImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        switch (strtolower($ext)) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($dstImage, $path, 85);
                break;
            case 'png':
                imagepng($dstImage, $path, 6);
                break;
            case 'gif':
                imagegif($dstImage, $path);
                break;
            case 'webp':
                imagewebp($dstImage, $path, 85);
                break;
        }

        imagedestroy($srcImage);
        imagedestroy($dstImage);

        return true;
    }


    public function previewParsedSoal()
    {
        $rawHtml = $this->request->getPost('raw_text');
        $parsed = $this->extractQuestionsFromHtml($rawHtml);
        return $this->response->setJSON($parsed);
    }

    public function uploadImage()
    {
        helper(['filesystem', 'text']);

        // Log untuk debugging
        log_message('info', '[UploadImage] Upload request received');

        // CKEditor menggunakan 'upload' sebagai field name
        $file = $this->request->getFile('upload');
        
        // Fallback ke 'file' jika 'upload' tidak ada
        if (!$file || !$file->isValid()) {
            $file = $this->request->getFile('file');
        }

        // 1. Validasi keberadaan dan validitas file (termasuk deteksi MIME)
        if (!$file || !$file->isValid()) {
            $errorMsg = 'File tidak valid';
            if ($file && $file->getError()) {
                $errorMsg .= ': ' . $file->getErrorString();
            }
            log_message('error', '[UploadImage] ' . $errorMsg);
            return $this->response->setJSON(['error' => $errorMsg]);
        }

        if ($file->hasMoved()) {
            log_message('error', '[UploadImage] File sudah dipindahkan');
            return $this->response->setJSON(['error' => 'File sudah dipindahkan.']);
        }

        // 2. Validasi ekstensi dan MIME type (Double check)
        $allowedMimes = ['image/jpg', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $fileMime = $file->getMimeType();
        
        if (!in_array($fileMime, $allowedMimes)) {
            log_message('error', '[UploadImage] MIME type tidak diizinkan: ' . $fileMime);
            return $this->response->setJSON(['error' => 'Tipe file tidak diizinkan. Hanya gambar (JPG, PNG, GIF, WEBP) yang diperbolehkan.']);
        }

        // 3. Validasi konten file (cek apakah benar-benar gambar)
        if (!@getimagesize($file->getTempName())) {
            log_message('error', '[UploadImage] File bukan gambar valid');
            return $this->response->setJSON(['error' => 'Isi file bukan gambar yang valid.']);
        }

        // Folder relatif (selalu di bawah /public/)
        $relativePath = 'uploads/soal_images/';
        
        // Pastikan FCPATH terdefinisi dengan benar
        if (!defined('FCPATH') || empty(FCPATH)) {
            log_message('error', '[UploadImage] FCPATH not defined!');
            return $this->response->setJSON(['error' => 'System error: FCPATH not defined']);
        }
        
        $uploadPath = FCPATH . $relativePath;
        
        // Validasi path - HARUS di dalam public/
        $realUploadPath = realpath(dirname($uploadPath));
        $realPublicPath = realpath(FCPATH);
        
        if ($realUploadPath === false || strpos($realUploadPath, $realPublicPath) !== 0) {
            log_message('error', '[UploadImage] Invalid upload path detected!');
            log_message('error', '[UploadImage] Upload path: ' . $uploadPath);
            log_message('error', '[UploadImage] Real upload: ' . ($realUploadPath ?: 'false'));
            log_message('error', '[UploadImage] Real public: ' . $realPublicPath);
            return $this->response->setJSON(['error' => 'Invalid upload path']);
        }

        log_message('info', '[UploadImage] FCPATH: ' . FCPATH);
        log_message('info', '[UploadImage] Relative path: ' . $relativePath);
        log_message('info', '[UploadImage] Full upload path: ' . $uploadPath);
        log_message('info', '[UploadImage] ROOTPATH: ' . ROOTPATH);

        // Pastikan folder ada
        if (!is_dir($uploadPath)) {
            if (!mkdir($uploadPath, 0775, true)) {
                log_message('error', '[UploadImage] Gagal membuat folder: ' . $uploadPath);
                return $this->response->setJSON(['error' => 'Gagal membuat folder upload.']);
            }
            log_message('info', '[UploadImage] Folder created: ' . $uploadPath);
        }

        // Cek permission folder
        if (!is_writable($uploadPath)) {
            log_message('error', '[UploadImage] Folder tidak writable: ' . $uploadPath);
            return $this->response->setJSON(['error' => 'Folder upload tidak memiliki permission yang tepat.']);
        }

        // Simpan dengan nama unik
        $newName = $file->getRandomName();
        
        try {
            if (!$file->move($uploadPath, $newName)) {
                log_message('error', '[UploadImage] Gagal memindahkan file');
                return $this->response->setJSON(['error' => 'Gagal menyimpan file.']);
            }
        } catch (\Exception $e) {
            log_message('error', '[UploadImage] Exception: ' . $e->getMessage());
            return $this->response->setJSON(['error' => 'Error: ' . $e->getMessage()]);
        }

        // Buat URL publik relatif ke base_url()
        $fileUrl = base_url($relativePath . $newName);

        // Simpan path relatif (bukan URL absolut)
        $dbPath = $relativePath . $newName;

        log_message('info', '[UploadImage] Success: ' . $fileUrl);

        return $this->response->setJSON([
            'url' => $fileUrl,  // CKEditor expects 'url' key
            'location' => $fileUrl,  // Fallback for compatibility
            'db_path' => $dbPath,
            'uploaded' => 1, // CKEditor 5 expects this
        ]);
    }

    public function backup($id)
    {
        $bank = $this->bankModel->find($id);
        if (!$bank) {
            return redirect()->back()->with('error', 'Bank soal tidak ditemukan.');
        }

        // Cleanup old backups in WRITEPATH/uploads
        $files = glob(WRITEPATH . 'uploads/backup_bank_*.zip');
        foreach ($files as $file) {
            if (is_file($file) && (time() - filemtime($file) > 3600)) { // Delete files older than 1 hour
                unlink($file);
            }
        }

        $bankData = $this->bankModel
            ->select('cbt_question_banks.*, subjects.name AS subject_name, subjects.code AS subject_code')
            ->join('subjects', 'subjects.id = cbt_question_banks.subject_id', 'left')
            ->find($id);

        $questions = $this->questionModel->where('bank_id', $id)->findAll();

        $data = [
            'bank' => $bankData,
            'questions' => $questions,
            'backup_at' => date('Y-m-d H:i:s'),
            'base_url' => base_url()
        ];

        $zip = new \ZipArchive();
        $zipName = 'backup_bank_' . $bank['code'] . '_' . date('Ymd_His') . '.zip';
        $zipPath = WRITEPATH . 'uploads/' . $zipName;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
            return redirect()->back()->with('error', 'Gagal membuat file backup.');
        }

        // Add data.json
        $zip->addFromString('data.json', json_encode($data, JSON_PRETTY_PRINT));

        // Add media files
        foreach ($questions as $q) {
            // Images from media_image field
            $images = json_decode($q['media_image'], true) ?? [];
            foreach ($images as $img) {
                // If it's a full URL, try to get the relative path
                $relativePath = str_replace(base_url(), '', $img);
                $relativePath = ltrim($relativePath, '/');
                $fullPath = FCPATH . $relativePath;

                if (file_exists($fullPath)) {
                    $zip->addFile($fullPath, 'uploads/' . $relativePath);
                }
            }

            // Audio files
            $audioFields = ['media_audio', 'audio_a', 'audio_b', 'audio_c', 'audio_d', 'audio_e'];
            foreach ($audioFields as $field) {
                if (!empty($q[$field])) {
                    $audioPath = 'uploads/audio/' . $q[$field];
                    $fullAudioPath = FCPATH . $audioPath;
                    if (file_exists($fullAudioPath)) {
                        $zip->addFile($fullAudioPath, $audioPath);
                    }
                }
            }

            // Video files
            if (!empty($q['media_video'])) {
                $videoPath = 'uploads/video/' . $q['media_video'];
                $fullVideoPath = FCPATH . $videoPath;
                if (file_exists($fullVideoPath)) {
                    $zip->addFile($fullVideoPath, $videoPath);
                }
            }

            // Also check for images in question_text (CKEditor)
            preg_match_all('/<img[^>]+src="([^">]+)"/i', $q['question_text'], $matches);
            $imgSrcs = $matches[1] ?? [];
            foreach ($imgSrcs as $src) {
                if (strpos($src, base_url()) !== false) {
                    $relativePath = str_replace(base_url(), '', $src);
                    $relativePath = ltrim($relativePath, '/');
                    $fullPath = FCPATH . $relativePath;
                    if (file_exists($fullPath)) {
                        $zip->addFile($fullPath, 'uploads/' . $relativePath);
                    }
                }
            }
        }

        $zip->close();

        return $this->response->download($zipPath, null);
    }

    public function restore()
    {
        $file = $this->request->getFile('backup_file');
        if (!$file || !$file->isValid()) {
            return redirect()->back()->with('error', 'File tidak valid.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($file->getTempName()) !== TRUE) {
            return redirect()->back()->with('error', 'Gagal membuka file ZIP.');
        }

        $dataJson = $zip->getFromName('data.json');
        if (!$dataJson) {
            $zip->close();
            return redirect()->back()->with('error', 'File data.json tidak ditemukan dalam ZIP.');
        }

        $data = json_decode($dataJson, true);
        if (!$data || !isset($data['bank']) || !isset($data['questions'])) {
            $zip->close();
            return redirect()->back()->with('error', 'Format data.json tidak valid.');
        }

        $oldBaseUrl = $data['base_url'] ?? '';
        $newBaseUrl = base_url();

        // 1. Prepare Bank Soal Data
        $bankData = $data['bank'];
        $oldId = $bankData['id'];
        unset($bankData['id']);

        // 🧠 SMART MAPPING: Match subject by Code or Name
        $user = session()->get('user');
        $currentTeacherId = $user['teacher_id'] ?? null;
        $bankData['teacher_id'] = $currentTeacherId;

        $subjectModel = new \App\Models\SubjectModel();
        $oldSubjectCode = $bankData['subject_code'] ?? null;
        $oldSubjectName = $bankData['subject_name'] ?? null;
        $localSubject = null;

        if ($oldSubjectCode) {
            $localSubject = $subjectModel->where('code', $oldSubjectCode)->first();
        }

        if (!$localSubject && $oldSubjectName) {
            $localSubject = $subjectModel->where('name', $oldSubjectName)->first();
        }

        if ($localSubject) {
            // Found a matching local subject
            $bankData['subject_id'] = $localSubject['id'];
        } else if ($oldSubjectCode && $oldSubjectName) {
            // Not found, but we have enough info to create it
            $newSubjectId = $subjectModel->insert([
                'code' => $oldSubjectCode,
                'name' => $oldSubjectName,
                'subject_type' => $bankData['subject_type'] ?? 'umum',
                'is_active' => 1
            ]);
            $bankData['subject_id'] = $newSubjectId;
        }
        // If it's a legacy backup without name/code, it will keep its old ID (might mismatch but best we can do)

        // Clean up fields that might not exist in target database or are meta-fields
        unset($bankData['subject_name']);
        unset($bankData['subject_code']);
        unset($bankData['teacher_name']);

        // Handle code collision
        $originalCode = $bankData['code'];
        $count = 0;
        while ($this->bankModel->where('code', $bankData['code'])->countAllResults() > 0) {
            $count++;
            $bankData['code'] = $originalCode . '_RESTORED_' . $count;
        }

        $bankData['created_at'] = date('Y-m-d H:i:s');
        $bankData['updated_at'] = date('Y-m-d H:i:s');
        $bankData['is_active'] = 0;

        try {
            $newBankId = $this->bankModel->insert($bankData);
        } catch (\mysqli_sql_exception $e) {
            $zip->close();
            return redirect()->back()->with('error', 'Gagal menyimpan bank soal: ' . $e->getMessage());
        }

        // 2. Extract and Copy Media Files
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // SECURITY: Sanitize path and prevent traversal
            if (strpos($filename, 'uploads/') === 0) {
                // Ensure no '..' in filename
                if (strpos($filename, '..') !== false)
                    continue;

                $content = $zip->getFromIndex($i);

                // Clean filename to prevent any absolute or parent path issues
                $cleanFilename = str_replace(['uploads/', '\\'], ['', '/'], $filename);
                $targetPath = FCPATH . 'uploads/' . ltrim($cleanFilename, '/');

                // Final check to ensure we are still inside FCPATH . 'uploads/'
                if (strpos(realpath(dirname($targetPath)) ?: '', realpath(FCPATH . 'uploads')) !== 0) {
                    // If realpath check fails because dir doesn't exist, we'll check it after creation
                    // but usually the strpos check is enough if we control the prefix.
                }

                $dir = dirname($targetPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                file_put_contents($targetPath, $content);
            }
        }

        // 3. Process Questions
        $stats = [
            'total_questions'   => count($data['questions']),
            'total_pg'          => 0,
            'total_esai'        => 0,
            'total_pg_kompleks' => 0,
            'total_bs'          => 0
        ];

        // Kolom yang benar-benar ada di tabel cbt_questions target.
        // Backup dari aplikasi lain (SIAKAD) bisa membawa kolom asing
        // yang membuat insert gagal/terpotong.
        $allowedCols = [
            'bank_id', 'question_text', 'raw_text',
            'option_a', 'option_b', 'option_c', 'option_d', 'option_e',
            'correct_option', 'question_type', 'essay_answer', 'score',
            'media_image', 'media_audio', 'media_video',
            'has_image', 'has_audio',
            'audio_a', 'audio_b', 'audio_c', 'audio_d', 'audio_e',
        ];

        $failed = 0;
        foreach ($data['questions'] as $q) {
            unset($q['id']);
            $q['bank_id'] = $newBankId;

            // ── Cross-fill teks soal ────────────────────────────────────────
            // Jaminan: question_text & raw_text tidak boleh kosong bersamaan,
            // karena tampilan list pakai question_text dan editor pakai raw_text.
            $qText   = trim((string) ($q['question_text'] ?? ''));
            $rawText = trim((string) ($q['raw_text'] ?? ''));

            if ($qText === '' && $rawText !== '') {
                // Turunkan question_text dari raw_text
                try {
                    $parsed = $this->questionModel->parseRawQuestion($rawText);
                    $qText = trim((string) ($parsed['question'] ?? ''));
                } catch (\Throwable $e) {
                    $qText = '';
                }
                if ($qText === '') {
                    $qText = trim(strip_tags($rawText)); // fallback terakhir
                }
            }
            if ($rawText === '' && $qText !== '') {
                $rawText = $qText; // editor tetap punya isi
            }

            $q['question_text'] = $qText;
            $q['raw_text']      = $rawText;

            // Normalize URLs di teks soal & raw_text
            if (!empty($oldBaseUrl)) {
                $q['question_text'] = str_replace($oldBaseUrl, $newBaseUrl, $q['question_text']);
                $q['raw_text']      = str_replace($oldBaseUrl, $newBaseUrl, $q['raw_text']);
            }

            // Normalize media_image URLs (dukung JSON array maupun string tunggal)
            $rawMedia = $q['media_image'] ?? '';
            $images = [];
            if (is_string($rawMedia) && $rawMedia !== '') {
                $decoded = json_decode($rawMedia, true);
                $images = is_array($decoded) ? $decoded : [$rawMedia];
            } elseif (is_array($rawMedia)) {
                $images = $rawMedia;
            }
            $newImages = [];
            foreach ($images as $img) {
                $newImages[] = $oldBaseUrl ? str_replace($oldBaseUrl, $newBaseUrl, $img) : $img;
            }
            $q['media_image'] = json_encode($newImages);

            // Buang kolom asing yang tidak ada di tabel target
            $q = array_intersect_key($q, array_flip($allowedCols));

            // Lewati soal yang benar-benar tidak punya teks
            if (trim((string) $q['question_text']) === '') {
                $failed++;
                log_message('warning', '[Restore CBT] Soal tanpa teks dilewati.');
                continue;
            }

            try {
                if (!$this->questionModel->insert($q)) {
                    $failed++;
                    log_message('error', '[Restore CBT] Insert gagal: '
                        . json_encode($this->questionModel->errors()));
                    continue;
                }
            } catch (\Throwable $e) {
                $failed++;
                log_message('error', '[Restore CBT] Insert exception: ' . $e->getMessage());
                continue;
            }

            // Update stats
            $type = $q['question_type'] ?? 'pg';
            if ($type === 'pg') $stats['total_pg']++;
            elseif ($type === 'esai') $stats['total_esai']++;
            elseif ($type === 'pg_kompleks') $stats['total_pg_kompleks']++;
            elseif ($type === 'benar_salah') $stats['total_bs']++;
        }

        // Update the bank soal cache
        $this->bankModel->update($newBankId, $stats);

        $zip->close();

        $msg = 'Bank soal berhasil di-restore dengan kode: ' . $bankData['code'];
        $imported = ($stats['total_pg'] ?? 0) + ($stats['total_esai'] ?? 0)
            + ($stats['total_pg_kompleks'] ?? 0) + ($stats['total_bs'] ?? 0);
        $msg .= ' (' . $imported . ' soal masuk';
        if ($failed > 0) {
            $msg .= ', ' . $failed . ' dilewati - cek log';
        }
        $msg .= ').';

        return redirect()->to('/admin/cbt/banksoal')->with('success', $msg);
    }

    /**
     * Get fresh CSRF token for AJAX requests
     */
    public function csrfRefresh()
    {
        return $this->response->setJSON([
            'token_name' => csrf_token(),
            'token_hash' => csrf_hash()
        ]);
    }

}


