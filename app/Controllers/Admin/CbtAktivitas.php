<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\CbtSessionModel;
use App\Models\CbtTestStatusModel;
use App\Models\CbtAnswerModel;
use App\Models\CbtQuestionModel;
use App\Models\StudentModel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Dompdf\Dompdf;
use App\Services\CbtScoringService;
use App\Services\CbtQuestionService;

class CbtAktivitas extends BaseController
{
    protected $sessionModel;
    protected $testModel;
    protected $answerModel;
    protected $questionModel;
    protected $studentModel;
    protected $scoringService;
    protected $questionService;
    protected $db;

    public function __construct()
    {
        $this->sessionModel = new CbtSessionModel();
        $this->testModel = new CbtTestStatusModel();
        $this->answerModel = new CbtAnswerModel();
        $this->questionModel = new CbtQuestionModel();
        $this->studentModel = new StudentModel();
        $this->scoringService = new CbtScoringService();
        $this->questionService = new CbtQuestionService();
        $this->db = db_connect();
    }



    /**
     * 📊 Halaman utama daftar aktivitas (index)
     * Menampilkan daftar ujian yang sudah mulai DAN ada siswa yang mengerjakan
     */
    public function index()
    {
        $now = date('Y-m-d H:i:s');

        $query = $this->db->table('cbt_test_status ts')
            ->select('ts.id AS test_id, qb.code AS test_code, s.name AS subject_name, en.name AS exam_name, ts.start_time, ts.end_time')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = ts.exam_name_id', 'left');

        // KRITERIA OTOMATIS: 
        // 1. Ujian sudah masuk waktu mulai (start_time <= now)
        // 2. Minimal ada 1 siswa yang sudah/sedang mengerjakan (exists in cbt_sessions)
        $query->where('ts.start_time <=', $now);
        $query->where("EXISTS (SELECT 1 FROM cbt_sessions cs WHERE cs.test_id = ts.id)", null, false);

        $sessions = $query
            ->orderBy('ts.start_time', 'DESC')
            ->get()->getResultArray();

        // Add status indicator for each exam
        foreach ($sessions as &$session) {
            $startTime = strtotime($session['start_time']);
            $endTime = strtotime($session['end_time']);
            $currentTime = strtotime($now);

            if ($currentTime >= $startTime && $currentTime <= $endTime) {
                $session['status'] = 'ongoing'; // Sedang berlangsung
            } elseif ($currentTime > $endTime) {
                $session['status'] = 'finished'; // Sudah selesai
            } else {
                $session['status'] = 'upcoming'; // Belum dimulai
            }
        }

        $classes = $this->db->table('classes')->select('id, name')->get()->getResultArray();

        return view('admin/cbt/aktivitas/index', [
            'sessions' => $sessions,
            'classes' => $classes
        ]);
    }

    /**
     * Alias untuk index() sesuai route lama
     */
    public function aktivitasIndex()
    {
        return $this->index();
    }

    /**
     * 📈 Detail aktivitas ujian (per tes)
     */
    public function detail($testId)
    {
        // 🔹 FORCE NO CACHE - Prevent browser caching
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
        
        $db = $this->db;

        // 🔹 CLEAR CACHE untuk memastikan data fresh
        cache()->delete('cbt_test_detail_' . $testId);

        // Get test info (no cache for debugging)
        $test = $db->table('cbt_test_status ts')
            ->select('ts.*, qb.code AS code, s.name AS subject_name, en.name AS exam_name')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = ts.exam_name_id', 'left')
            ->where('ts.id', $testId)
            ->get()
            ->getRowArray();

        if (!$test) {
            return redirect()->to(site_url('admin/cbt/aktivitas'))->with('error', 'Data ujian tidak ditemukan.');
        }

        // 🔹 FIX V3: Get all sessions, log them, then filter
        $allSessions = $db->table('cbt_sessions cs')
            ->select('cs.id, cs.test_id, cs.student_id, cs.question_order, cs.option_orders,
                      cs.status, cs.score, cs.essay_score, cs.total_score, cs.started_at, 
                      cs.finished_at, cs.last_activity, cs.extra_time, cs.updated_at, cs.created_at,
                      st.name AS student_name, st.nis, st.username, st.room, c.name AS class_name')
            ->join('students st', 'st.id = cs.student_id', 'left')
            ->join('classes c', 'c.id = st.class_id', 'left')
            ->where('cs.test_id', $testId)
            ->orderBy('cs.id', 'DESC') // Latest first
            ->get()->getResultArray();

        log_message('info', "[DetailAktivitas] Test ID: $testId - Total sessions from DB: " . count($allSessions));

        // Debug: Log all sessions
        foreach ($allSessions as $idx => $s) {
            log_message('debug', "[DetailAktivitas] Session #{$idx}: ID={$s['id']}, Student={$s['student_id']} ({$s['student_name']}), Status={$s['status']}");
        }

        // Filter to get only latest session per student
        $seenStudents = [];
        $sessions = [];
        foreach ($allSessions as $session) {
            $studentId = $session['student_id'];
            if (!isset($seenStudents[$studentId])) {
                $seenStudents[$studentId] = true;
                $sessions[] = $session;
                log_message('debug', "[DetailAktivitas] KEEPING Session ID={$session['id']} for Student={$studentId} ({$session['student_name']})");
            } else {
                log_message('debug', "[DetailAktivitas] SKIPPING Session ID={$session['id']} for Student={$studentId} ({$session['student_name']}) - already have newer session");
            }
        }

        log_message('info', "[DetailAktivitas] Test ID: $testId - Unique students after filter: " . count($sessions));

        // Sort by class and name
        usort($sessions, function($a, $b) {
            $classCompare = strcmp($a['class_name'] ?? '', $b['class_name'] ?? '');
            if ($classCompare !== 0) return $classCompare;
            return strcmp($a['student_name'] ?? '', $b['student_name'] ?? '');
        });

        // Get violation counts for all students in this test (single query)
        $violationCounts = $db->table('cbt_cheat_logs')
            ->select('student_id, COUNT(*) as violation_count')
            ->where('test_id', $testId)
            ->groupBy('student_id')
            ->get()
            ->getResultArray();

        // Map violation counts by student_id
        $violationMap = [];
        foreach ($violationCounts as $v) {
            $violationMap[$v['student_id']] = $v['violation_count'];
        }

        // Get questions (no cache for debugging)
        $questions = $db->table('cbt_questions')
            ->where('bank_id', $test['bank_id'])
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $noBankMap = [];
        $counter = 1;
        foreach ($questions as $q) {
            $noBankMap[$q['id']] = $counter++;
        }

        // Hitung sisa waktu per sesi
        $now = time();
        foreach ($sessions as &$s) {
            $order = json_decode($s['question_order'], true) ?? [];
            $noUserMap = [];
            $i = 1;
            foreach ($order as $qid) {
                $noUserMap[$qid] = $i++;
            }

            $s['no_bank_map'] = $noBankMap;
            $s['no_user_map'] = $noUserMap;
            $s['violation_count'] = $violationMap[$s['student_id']] ?? 0;

            // Hitung sisa waktu
            $startTs = 0;
            if (!empty($s['started_at'])) {
                $startTs = is_numeric($s['started_at']) ? (int) $s['started_at'] : strtotime((string) $s['started_at']);
                // Safety check: if startTs is invalid (e.g. 0), use now to avoid wild math
                if (!$startTs || $startTs < 100000)
                    $startTs = $now;

                // Update started_at to timestamp for the view
                $s['started_at'] = $startTs;

                $endTs = $now;

                // Jika status finished, "waktu berhenti" adalah finished_at
                // Jika finished_at tidak valid, gunakan updated_at sebagai fallback terbaik
                if ($s['status'] === 'finished') {
                    $finishValid = false;
                    if (!empty($s['finished_at'])) {
                        $fStr = (string) $s['finished_at'];
                        if ($fStr !== '0' && substr($fStr, 0, 10) !== '0000-00-00') {
                            $parsedEnd = is_numeric($fStr) ? (int) $fStr : strtotime($fStr);
                            if ($parsedEnd > 315360000) {
                                $endTs = $parsedEnd;
                                $finishValid = true;
                            }
                        }
                    }

                    if (!$finishValid && !empty($s['updated_at'])) {
                        $uStr = (string) $s['updated_at'];
                        $parsedUpd = is_numeric($uStr) ? (int) $uStr : strtotime($uStr);
                        if ($parsedUpd > 315360000) {
                            $endTs = $parsedUpd;
                        }
                    }
                }

                // Pastikan End tidak lebih kecil dari Start
                if ($endTs < $startTs) {
                    $endTs = $startTs;
                }

                $elapsed = ($endTs - $startTs) / 60;
                $remaining = $test['duration'] - $elapsed;
                $s['remaining_minutes'] = max(0, floor($remaining));
            } else {
                $s['started_at'] = 0;
                $s['remaining_minutes'] = 0;
            }

            // Fallback score agar tidak "KOSONG" jika salah satu null
            $s['display_score'] = $s['total_score'] ?? $s['score'] ?? 0;
        }
        unset($s); // 🔹 CRITICAL: Break reference to avoid corruption in next loop

        // Hitung jumlah yang belum selesai (status active)
        $count_belum = 0;
        foreach ($sessions as $s) {
            if ($s['status'] === 'active') {
                $count_belum++;
            }
        }

        log_message('info', "[DetailAktivitas] Test ID: $testId - Final sessions to display: " . count($sessions) . ", Active: $count_belum");

        // 🔹 Extract available classes and rooms for filtering
        $availableClasses = [];
        $availableRooms = [];
        foreach ($sessions as $s) {
            if (!empty($s['class_name']) && !in_array($s['class_name'], $availableClasses)) {
                $availableClasses[] = $s['class_name'];
            }
            if (!empty($s['room']) && !in_array($s['room'], $availableRooms)) {
                $availableRooms[] = $s['room'];
            }
        }
        sort($availableClasses);
        sort($availableRooms);

        return view('admin/cbt/aktivitas/detail', [
            'test' => $test,
            'sessions' => $sessions,
            'noBankMap' => $noBankMap,
            'count_belum' => $count_belum,
            'availableClasses' => $availableClasses,
            'availableRooms' => $availableRooms
        ]);
    }

    private function getRemainingMinutes($session)
    {
        if (empty($session['started_at']))
            return 0;
        $duration = (int) ($session['duration'] ?? 0);
        $elapsed = (time() - (int) $session['started_at']) / 60;
        return round(max(0, $duration - $elapsed));
    }

    /**
     * 📄 Modal detail jawaban siswa
     */
    /**
     * 📄 Modal detail jawaban siswa — real-time, rincian soal & opsi sesuai tampilan siswa (Rata Kiri & Live Sync)
     */
    public function detail_jawaban($sessionId)
    {
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Expires', '0');

        $db = db_connect();

        $roomField = "st.room";
        if (!$db->fieldExists('room', 'students')) {
            $roomField = "'' AS room";
        }

        // 1) Ambil session & test info
        $session = $db->table('cbt_sessions s')
            ->select('s.id AS session_id, s.student_id, s.test_id, s.question_order, s.option_orders,
                      s.status, s.score, s.total_score, s.essay_score, s.started_at, s.finished_at, s.updated_at,
                      ts.bank_id, ts.duration,
                      ts.bobot_pg, ts.bobot_pg_kompleks, ts.bobot_bs, ts.bobot_esai,
                      ts.show_pg_count, ts.show_pg_kompleks_count, ts.show_bs_count, ts.show_esai_count,
                      st.name AS student_name, st.nis,
                      u.username,
                      c.name AS class_name, ' . $roomField)
            ->join('cbt_test_status ts',    'ts.id = s.test_id',                       'left')
            ->join('students st',           'st.id = s.student_id',                    'left')
            ->join('users u',               'u.id = st.user_id',                       'left')
            ->join('classes c',             'c.id = st.class_id',                      'left')
            ->where('s.id', $sessionId)
            ->get()
            ->getRowArray();

        if (!$session) {
            return '<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i> Sesi ujian tidak ditemukan.</div>';
        }

        $bankId    = $session['bank_id'];
        $studentId = $session['student_id'];
        $testId    = $session['test_id'];

        // 2) Ambil semua soal dari bank
        $bankQuestions = $db->table('cbt_questions')
            ->select('id, question_text, raw_text, question_type, correct_option, option_a, option_b, option_c, option_d, option_e, score')
            ->where('bank_id', $bankId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $baseUrl = base_url();
        $rewriteImageUrls = function(string $html) use ($baseUrl): string {
            if (empty($html) || strpos($html, '<img') === false) return $html;
            $html = preg_replace('#src="https?://localhost(?::\d+)?/(uploads/)#i', 'src="' . $baseUrl . '', $html);
            $html = preg_replace('#src="https?://127\.0\.0\.1(?::\d+)?/(uploads/)#i', 'src="' . $baseUrl . '', $html);
            $html = preg_replace('#src="https?://[^"]+/public/(uploads/)#i', 'src="' . $baseUrl . '', $html);
            $html = preg_replace('#src="(?!https?://)(uploads/)#i', 'src="' . $baseUrl . '', $html);
            return $html;
        };

        $cleanQuestionText = function(string $html): string {
            if (empty($html)) return '';
            $out = $html;
            $out = preg_replace('/<p[^>]*>\s*[\(]?[A-Ea-e]\s*[:.)\-]\s*[^<]*<\/p>/i', '', $out);
            $out = preg_replace('/^[\(]?[A-Ea-e]\s*[:.)\-]\s*[^\n]*$/im', '', $out);
            $out = preg_replace('/<p[^>]*>\s*Kunci\s*[\:\=\-]\s*[^<]*<\/p>/i', '', $out);
            return trim($out);
        };

        $questionService = class_exists('\App\Services\CbtQuestionService') ? new \App\Services\CbtQuestionService() : null;

        $questionMap = [];
        $noBankMap   = [];
        $no = 1;
        foreach ($bankQuestions as $q) {
            $parsed = null;
            if ($questionService && !empty($q['raw_text']) && (empty($q['question_text']) || strlen(strip_tags($q['question_text'])) < 10)) {
                try {
                    $parsed = $questionService->parseRawQuestion($q['raw_text']);
                } catch (\Throwable $e) {
                    $parsed = null;
                }
            }

            $type = strtolower(trim($q['question_type'] ?? ''));
            if (in_array($type, ['pilihan ganda', 'single', 'pilihan_ganda', 'pg'])) {
                $type = 'pg';
            } elseif (in_array($type, ['multiple', 'pg_kompleks', 'pilihan ganda kompleks'])) {
                $type = 'pg_kompleks';
            } elseif (in_array($type, ['boolean', 'benar_salah', 'benar salah', 'bs'])) {
                $type = 'benar_salah';
            } elseif (in_array($type, ['esai', 'essay'])) {
                $type = 'esai';
            } else {
                $type = 'pg';
            }

            $qText = !empty($q['question_text']) ? $q['question_text'] : ($parsed['question_text'] ?? $q['raw_text']);
            $qText = $rewriteImageUrls($cleanQuestionText($qText));

            $optA = !empty($q['option_a']) ? $q['option_a'] : ($parsed['options']['A'] ?? '');
            $optB = !empty($q['option_b']) ? $q['option_b'] : ($parsed['options']['B'] ?? '');
            $optC = !empty($q['option_c']) ? $q['option_c'] : ($parsed['options']['C'] ?? '');
            $optD = !empty($q['option_d']) ? $q['option_d'] : ($parsed['options']['D'] ?? '');
            $optE = !empty($q['option_e']) ? $q['option_e'] : ($parsed['options']['E'] ?? '');

            $correct = trim($q['correct_option'] ?? ($parsed['correct_option'] ?? ''));

            $questionMap[$q['id']] = [
                'id'             => $q['id'],
                'type'           => $type,
                'question_text'  => $qText,
                'correct_option' => $correct,
                'score'          => (float)($q['score'] ?? 1),
                'options'        => [
                    'A' => $rewriteImageUrls($optA),
                    'B' => $rewriteImageUrls($optB),
                    'C' => $rewriteImageUrls($optC),
                    'D' => $rewriteImageUrls($optD),
                    'E' => $rewriteImageUrls($optE),
                ]
            ];
            $noBankMap[$q['id']] = $no++;
        }

        // 3) Ambil urutan soal & acak opsi siswa
        $questionOrder = json_decode($session['question_order'] ?? '[]', true);
        if (!is_array($questionOrder) || empty($questionOrder)) {
            $questionOrder = array_keys($questionMap);
        }

        $sessionOptionOrders = json_decode($session['option_orders'] ?? '[]', true);
        if (!is_array($sessionOptionOrders)) {
            $sessionOptionOrders = [];
        }

        // 4) Ambil semua jawaban siswa
        $answerRows = $db->table('cbt_answers')
            ->where('student_id', $studentId)
            ->where('test_id', $testId)
            ->get()
            ->getResultArray();

        $answerMap = [];
        $latestAnswerTimestamp = 0;
        foreach ($answerRows as $a) {
            $answerMap[$a['question_id']] = [
                'answer' => $a['answer'] ?? '',
                'score'  => isset($a['score']) ? (float)$a['score'] : null
            ];
            if (!empty($a['updated_at'])) {
                $t = strtotime($a['updated_at']);
                if ($t > $latestAnswerTimestamp) $latestAnswerTimestamp = $t;
            }
        }

        // 5) Hitung statistik progres & penilaian
        $totalSoal  = count($questionOrder);
        $totalJawab = 0;
        $totalBenar = 0;
        $totalSalah = 0;
        $totalBelum = 0;

        $questionResultList = [];

        foreach ($questionOrder as $idx => $qid) {
            $qData = $questionMap[$qid] ?? null;
            if (!$qData) continue;

            $noUser = $idx + 1;
            $ansData = $answerMap[$qid] ?? null;
            $answered = false;
            $studentRaw = '';
            $scoreGiven = null;

            if ($ansData !== null) {
                $studentRaw = $ansData['answer'];
                $scoreGiven = $ansData['score'];
                if ($studentRaw !== null && trim((string)$studentRaw) !== '' && $studentRaw !== 'null' && $studentRaw !== '[]') {
                    $answered = true;
                }
            }

            if ($answered) $totalJawab++;
            else $totalBelum++;

            $type = $qData['type'];
            $correct = strtoupper(trim($qData['correct_option']));
            $studentUpper = strtoupper(trim((string)$studentRaw));
            $isCorrect = false;
            $isPartial = false;

            if ($answered) {
                if ($type === 'pg') {
                    $isCorrect = ($studentUpper === $correct);
                } elseif ($type === 'pg_kompleks') {
                    $sArr = array_filter(array_map('trim', explode(',', $studentUpper)));
                    $cArr = array_filter(array_map('trim', explode(',', $correct)));
                    sort($sArr); sort($cArr);
                    $isCorrect = ($sArr === $cArr);
                    if (!$isCorrect && count(array_intersect($sArr, $cArr)) > 0) {
                        $isPartial = true;
                    }
                } elseif ($type === 'benar_salah') {
                    $sArr = array_map('trim', explode(',', $studentUpper));
                    $cArr = array_map('trim', explode(',', $correct));
                    $isCorrect = ($sArr === $cArr);
                } elseif ($type === 'esai') {
                    $isCorrect = ($scoreGiven !== null && $scoreGiven > 0);
                }
            }

            if ($answered) {
                if ($type === 'esai') {
                    $resStatus = 'esai';
                } elseif ($isCorrect) {
                    $totalBenar++;
                    $resStatus = 'correct';
                } elseif ($isPartial) {
                    $resStatus = 'partial';
                } else {
                    $totalSalah++;
                    $resStatus = 'incorrect';
                }
            } else {
                $resStatus = 'unanswered';
            }

            $questionResultList[$qid] = [
                'no_user'      => $noUser,
                'no_bank'      => $noBankMap[$qid] ?? $noUser,
                'type'         => $type,
                'answered'     => $answered,
                'status'       => $resStatus,
                'student_ans'  => $studentRaw,
                'correct_ans'  => $correct,
                'qdata'        => $qData
            ];
        }

        $percentJawab = $totalSoal > 0 ? round(($totalJawab / $totalSoal) * 100) : 0;
        $totalObjektif = max(1, $totalBenar + $totalSalah);
        $percentBenar = round(($totalBenar / $totalObjektif) * 100);

        $statusBadge = match($session['status']) {
            'active'   => "<span class='badge bg-warning text-dark border border-warning'><i class='fas fa-pencil-alt me-1'></i>Sedang Mengerjakan</span>",
            'finished' => "<span class='badge bg-success'><i class='fas fa-check-circle me-1'></i>Selesai</span>",
            default    => "<span class='badge bg-secondary'>" . esc($session['status']) . "</span>",
        };

        $nilaiDisplay = '-';
        if ($session['total_score'] !== null) {
            $nilaiDisplay = number_format((float)$session['total_score'], 1);
        } elseif ($session['score'] !== null) {
            $nilaiDisplay = number_format((float)$session['score'], 1);
        }

        $updatedAt = $latestAnswerTimestamp > 0 ? date('d/m/Y H:i:s', $latestAnswerTimestamp) : (!empty($session['started_at']) ? date('d/m/Y H:i:s', strtotime($session['started_at'])) : '-');

        // 6) Render HTML Modal
        $html = "<div id='sessionStatusMarker' data-session-status='{$session['status']}' class='d-none'></div>";

        if ($session['status'] === 'active') {
            $html .= "
            <div class='alert alert-success bg-success-subtle border-success py-2 px-3 d-flex flex-wrap justify-content-between align-items-center mb-3 rounded-3 text-start' style='text-align:left;'>
                <div class='d-flex align-items-center gap-2'>
                    <span class='badge bg-success d-flex align-items-center gap-1'><i class='fas fa-broadcast-tower me-1'></i> LIVE AKTIF</span>
                    <span class='small fw-semibold text-success-emphasis'>Siswa sedang mengerjakan ujian. Jawaban dan progres diperbarui secara langsung.</span>
                </div>
                <div class='small text-muted'>
                    <span class='me-2'><i class='fas fa-clock me-1 text-success'></i>Aktivitas Terakhir: <strong>{$updatedAt}</strong></span>
                    <span class='badge bg-white text-secondary border'><i class='fas fa-sync-alt fa-spin me-1'></i>" . date('H:i:s') . "</span>
                </div>
            </div>";
        } elseif ($session['status'] === 'finished') {
            $html .= "
            <div class='alert alert-primary bg-primary-subtle border-primary py-2 px-3 d-flex flex-wrap justify-content-between align-items-center mb-3 rounded-3 text-start' style='text-align:left;'>
                <div class='d-flex align-items-center gap-2'>
                    <span class='badge bg-primary'><i class='fas fa-check-circle me-1'></i> SELESAI</span>
                    <span class='small fw-semibold text-primary-emphasis'>Siswa telah menyelesaikan ujian ini.</span>
                </div>
                <div class='small text-muted'>
                    <span>Waktu Selesai: <strong>" . (!empty($session['finished_at']) ? date('d/m/Y H:i:s', strtotime($session['finished_at'])) : $updatedAt) . "</strong></span>
                </div>
            </div>";
        }

        $html .= "
        <div class='modal-student-info mb-3 p-3 border rounded-3 bg-light shadow-sm text-start' style='text-align:left;'>
            <div class='d-flex flex-wrap justify-content-between align-items-center gap-2'>
                <div>
                    <h5 class='mb-1 fw-bold text-dark'>{$session['student_name']}</h5>
                    <div class='text-muted small'>
                        <span class='me-2'><i class='fas fa-id-card me-1 text-primary'></i>Username: <strong>{$session['username']}</strong></span>
                        <span class='me-2'><i class='fas fa-hashtag me-1 text-primary'></i>NIS: <strong>{$session['nis']}</strong></span>
                        <span class='me-2'><i class='fas fa-chalkboard-teacher me-1 text-primary'></i>Kelas: <strong>" . esc($session['class_name'] ?? '-') . "</strong></span>
                        <span class='me-2'><i class='fas fa-door-open me-1 text-primary'></i>Ruang: <strong>" . esc($session['room'] ?? '-') . "</strong></span>
                    </div>
                </div>
                <div class='d-flex align-items-center gap-2'>
                    <div>{$statusBadge}</div>
                    <div class='px-3 py-2 bg-white rounded-3 border text-center'>
                        <span class='small text-muted d-block' style='font-size:10px;'>SKOR / NILAI</span>
                        <h4 class='mb-0 fw-bold text-primary'>{$nilaiDisplay}</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class='row g-2 mb-3 text-center'>
            <div class='col'>
                <div class='p-2 border rounded bg-white'>
                    <small class='text-muted d-block'>Total Soal</small>
                    <h5 class='mb-0 fw-bold'>{$totalSoal}</h5>
                </div>
            </div>
            <div class='col'>
                <div class='p-2 border rounded bg-success-subtle border-success'>
                    <small class='text-muted d-block'>Terjawab</small>
                    <h5 class='mb-0 text-success fw-bold'>{$totalJawab}</h5>
                </div>
            </div>
            <div class='col'>
                <div class='p-2 border rounded bg-success text-white'>
                    <small class='d-block opacity-75'>Benar</small>
                    <h5 class='mb-0 fw-bold'>{$totalBenar}</h5>
                </div>
            </div>
            <div class='col'>
                <div class='p-2 border rounded bg-danger text-white'>
                    <small class='d-block opacity-75'>Salah</small>
                    <h5 class='mb-0 fw-bold'>{$totalSalah}</h5>
                </div>
            </div>
            <div class='col'>
                <div class='p-2 border rounded bg-warning-subtle border-warning'>
                    <small class='text-muted d-block'>Belum Dijawab</small>
                    <h5 class='mb-0 text-warning-emphasis fw-bold'>{$totalBelum}</h5>
                </div>
            </div>
        </div>

        <div class='d-flex justify-content-between align-items-center mb-1 small text-muted text-start' style='text-align:left;'>
            <span>Progres Pengerjaan: <strong>{$percentJawab}%</strong> ({$totalJawab}/{$totalSoal} soal)</span>
            <span>Akurasi Soal Objektif: <strong>{$percentBenar}%</strong></span>
        </div>
        <div class='progress mb-4' style='height: 8px;'>
            <div class='progress-bar bg-success progress-bar-striped progress-bar-animated' role='progressbar' style='width: {$percentJawab}%'></div>
        </div>

        <div class='mb-4 text-start' style='text-align:left;'>
            <div class='d-flex justify-content-between align-items-center mb-2'>
                <h6 class='mb-0 fw-bold text-dark'><i class='fas fa-th-large me-1 text-primary'></i> Peta Jawaban Siswa</h6>
                <div class='d-flex gap-2 small'>
                    <span class='d-flex align-items-center gap-1'><span class='badge bg-success p-1 rounded-circle'> </span> Benar</span>
                    <span class='d-flex align-items-center gap-1'><span class='badge bg-danger p-1 rounded-circle'> </span> Salah</span>
                    <span class='d-flex align-items-center gap-1'><span class='badge bg-warning p-1 rounded-circle'> </span> Sebagian</span>
                    <span class='d-flex align-items-center gap-1'><span class='badge bg-info p-1 rounded-circle'> </span> Esai</span>
                    <span class='d-flex align-items-center gap-1'><span class='badge bg-light text-dark border p-1 rounded-circle'> </span> Belum</span>
                </div>
            </div>
            <div class='d-flex flex-wrap gap-1 p-2 border rounded-3 bg-light justify-content-start text-start' style='text-align:left;'>";

        foreach ($questionOrder as $idx => $qid) {
            $r = $questionResultList[$qid] ?? null;
            if (!$r) continue;

            $noUser = $r['no_user'];
            $st = $r['status'];

            $gridColor = match($st) {
                'correct'    => 'bg-success text-white border-success',
                'partial'    => 'bg-warning text-dark border-warning',
                'incorrect'  => 'bg-danger text-white border-danger',
                'esai'       => 'bg-info text-white border-info',
                default      => 'bg-white text-muted border-secondary-subtle',
            };

            $tooltip = "Soal #{$noUser} (" . strtoupper($r['type']) . "): " . match($st) {
                'correct'   => 'BENAR',
                'partial'   => 'BENAR SEBAGIAN',
                'incorrect' => 'SALAH',
                'esai'      => 'TERJAWAB (ESAI)',
                default     => 'BELUM DIJAWAB',
            };

            $html .= "<a href='#soal-card-{$noUser}' class='btn btn-sm p-0 d-flex align-items-center justify-content-center {$gridColor}' 
                         style='width:34px; height:34px; font-size:12px; font-weight:700; text-decoration:none;' 
                         title='{$tooltip}'>
                        {$noUser}
                      </a>";
        }

        $html .= "
            </div>
        </div>

        <div class='d-flex justify-content-between align-items-center mb-3 border-bottom pb-2 text-start' style='text-align:left;'>
            <h6 class='fw-bold mb-0 text-dark'><i class='fas fa-file-alt me-1 text-primary'></i> Rincian Soal & Jawaban Siswa</h6>
            <small class='text-muted'>Nomor soal & opsi disesuaikan dengan tampilan layar siswa</small>
        </div>";

        // 7) Rincian Per Soal Lengkap
        foreach ($questionOrder as $idx => $qid) {
            $r = $questionResultList[$qid] ?? null;
            if (!$r) continue;

            $noUser   = $r['no_user'];
            $noBank   = $r['no_bank'];
            $type     = $r['type'];
            $qData    = $r['qdata'];
            $answered = $r['answered'];
            $res      = $r['status'];
            $qText    = $qData['question_text'];
            $correct  = $qData['correct_option'];
            $studentRaw = $r['student_ans'];
            $studentUpper = strtoupper(trim((string)$studentRaw));

            $statusBadge = match($res) {
                'correct'   => "<span class='badge bg-success px-2 py-1'><i class='fas fa-check me-1'></i>BENAR</span>",
                'partial'   => "<span class='badge bg-warning text-dark px-2 py-1'><i class='fas fa-adjust me-1'></i>SEBAGIAN</span>",
                'incorrect' => "<span class='badge bg-danger px-2 py-1'><i class='fas fa-times me-1'></i>SALAH</span>",
                'esai'      => "<span class='badge bg-info text-white px-2 py-1'><i class='fas fa-pen me-1'></i>ESAI</span>",
                default     => "<span class='badge bg-light text-muted border px-2 py-1'><i class='far fa-circle me-1'></i>BELUM DIJAWAB</span>",
            };

            $typeLabel = match($type) {
                'pg'          => "<span class='badge bg-primary-subtle text-primary border border-primary-subtle'>Pilihan Ganda</span>",
                'pg_kompleks' => "<span class='badge bg-info-subtle text-info-emphasis border border-info-subtle'>PG Kompleks</span>",
                'benar_salah' => "<span class='badge bg-warning-subtle text-warning-emphasis border border-warning-subtle'>Benar / Salah</span>",
                'esai'        => "<span class='badge bg-purple-subtle text-purple border'>Esai / Uraian</span>",
                default       => "<span class='badge bg-secondary-subtle text-secondary'>Soal</span>",
            };

            $opts = $qData['options'] ?? [];
            $displayOpts = [];

            if (isset($sessionOptionOrders[$qid]) && is_array($sessionOptionOrders[$qid])) {
                $screenIndex = 0;
                $screenLetters = ['A', 'B', 'C', 'D', 'E'];
                foreach ($sessionOptionOrders[$qid] as $bankLetter) {
                    $bankLetter = strtoupper(trim((string)$bankLetter));
                    if (isset($opts[$bankLetter])) {
                        $screenLetter = $screenLetters[$screenIndex] ?? $bankLetter;
                        $displayOpts[] = [
                            'bank_letter'   => $bankLetter,
                            'screen_letter' => $screenLetter,
                            'text'          => $opts[$bankLetter],
                            'is_shuffled'   => true
                        ];
                        $screenIndex++;
                    }
                }
            }

            if (empty($displayOpts)) {
                foreach (['A', 'B', 'C', 'D', 'E'] as $bankLetter) {
                    if (isset($opts[$bankLetter])) {
                        $displayOpts[] = [
                            'bank_letter'   => $bankLetter,
                            'screen_letter' => $bankLetter,
                            'text'          => $opts[$bankLetter],
                            'is_shuffled'   => false
                        ];
                    }
                }
            }

            $cardBorder = match($res) {
                'correct'   => 'border-success',
                'partial'   => 'border-warning',
                'incorrect' => 'border-danger',
                'esai'      => 'border-info',
                default     => 'border-secondary-subtle',
            };

            $html .= "
            <div class='card mb-3 shadow-sm {$cardBorder} text-start' id='soal-card-{$noUser}' style='text-align:left !important;'>
                <div class='card-header bg-light d-flex flex-wrap justify-content-between align-items-center py-2 px-3 text-start'>
                    <div class='d-flex align-items-center gap-2'>
                        <span class='badge bg-dark rounded-pill px-2 py-1'>Soal {$noUser}</span>
                        <span class='text-muted small'>(No. {$noBank} di Bank Soal)</span>
                        {$typeLabel}
                    </div>
                    <div>
                        {$statusBadge}
                    </div>
                </div>
                <div class='card-body p-3 text-start' style='text-align:left !important;'>
                    <!-- Teks Pertanyaan -->
                    <div class='question-content mb-3 text-dark text-start' style='line-height:1.6; text-align:left !important;'>
                        {$qText}
                    </div>";

            if ($type === 'pg') {
                $html .= "<div class='options-review-list mt-2 text-start' style='text-align:left !important;'>";
                foreach ($displayOpts as $optItem) {
                    $bankLetter   = $optItem['bank_letter'];
                    $screenLetter = $optItem['screen_letter'];
                    $isShuffled   = $optItem['is_shuffled'];
                    $optText      = $optItem['text'];

                    $isStudentChoice = ($answered && $studentUpper === $bankLetter);
                    $isCorrectKey    = ($correct === $bankLetter);

                    $optClass = 'border-secondary-subtle bg-white';
                    $badgeChoice = '';

                    if ($isStudentChoice && $isCorrectKey) {
                        $optClass = 'border-success bg-success-subtle text-success-emphasis fw-bold';
                        $badgeChoice = "<span class='badge bg-success ms-auto'><i class='fas fa-check me-1'></i>Pilihan Siswa & Kunci</span>";
                    } elseif ($isStudentChoice && !$isCorrectKey) {
                        $optClass = 'border-danger bg-danger-subtle text-danger-emphasis fw-bold';
                        $badgeChoice = "<span class='badge bg-danger ms-auto'><i class='fas fa-times me-1'></i>Pilihan Siswa</span>";
                    } elseif ($isCorrectKey) {
                        $optClass = 'border-success bg-success bg-opacity-10 text-success-emphasis';
                        $badgeChoice = "<span class='badge bg-success bg-opacity-75 text-white ms-auto'><i class='fas fa-key me-1'></i>Kunci Jawaban</span>";
                    }

                    $letterBadge = $isShuffled
                        ? "<span class='badge bg-primary text-white border px-2 py-1' style='font-size:12px;' title='Huruf di layar siswa: {$screenLetter}'>{$screenLetter}</span><span class='badge bg-light text-secondary border px-1 py-1' style='font-size:10px;' title='Kunci bank soal: {$bankLetter}'>Bank: {$bankLetter}</span>"
                        : "<span class='badge bg-secondary-subtle text-dark border px-2 py-1' style='font-size:12px;'>{$bankLetter}</span>";

                    $html .= "
                    <div class='p-2 mb-2 border rounded-3 d-flex align-items-start gap-2 text-start {$optClass}' style='text-align:left !important;'>
                        <div class='d-flex align-items-center gap-1 flex-shrink-0 pt-1'>
                            {$letterBadge}
                        </div>
                        <div class='flex-grow-1 text-start' style='text-align:left !important;'>{$optText}</div>
                        <div class='flex-shrink-0'>{$badgeChoice}</div>
                    </div>";
                }
                $html .= "</div>";

            } elseif ($type === 'pg_kompleks') {
                $studentKeys = array_filter(array_map('trim', explode(',', $studentUpper)));
                $correctKeys = array_filter(array_map('trim', explode(',', $correct)));

                $html .= "<div class='options-review-list mt-2 text-start' style='text-align:left !important;'>";
                foreach ($displayOpts as $optItem) {
                    $bankLetter   = $optItem['bank_letter'];
                    $screenLetter = $optItem['screen_letter'];
                    $isShuffled   = $optItem['is_shuffled'];
                    $optText      = $optItem['text'];

                    $isStudentChoice = in_array($bankLetter, $studentKeys);
                    $isCorrectKey    = in_array($bankLetter, $correctKeys);

                    $optClass = 'border-secondary-subtle bg-white';
                    $badgeChoice = '';

                    if ($isStudentChoice && $isCorrectKey) {
                        $optClass = 'border-success bg-success-subtle text-success-emphasis fw-bold';
                        $badgeChoice = "<span class='badge bg-success ms-auto'><i class='fas fa-check me-1'></i>Dipilih (Benar)</span>";
                    } elseif ($isStudentChoice && !$isCorrectKey) {
                        $optClass = 'border-danger bg-danger-subtle text-danger-emphasis fw-bold';
                        $badgeChoice = "<span class='badge bg-danger ms-auto'><i class='fas fa-times me-1'></i>Dipilih (Salah)</span>";
                    } elseif ($isCorrectKey) {
                        $optClass = 'border-success bg-success bg-opacity-10 text-success-emphasis';
                        $badgeChoice = "<span class='badge bg-success bg-opacity-75 text-white ms-auto'><i class='fas fa-key me-1'></i>Kunci Jawaban</span>";
                    }

                    $letterBadge = $isShuffled
                        ? "<span class='badge bg-primary text-white border px-2 py-1' style='font-size:12px;' title='Huruf di layar siswa: {$screenLetter}'>{$screenLetter}</span><span class='badge bg-light text-secondary border px-1 py-1' style='font-size:10px;' title='Kunci bank soal: {$bankLetter}'>Bank: {$bankLetter}</span>"
                        : "<span class='badge bg-secondary-subtle text-dark border px-2 py-1' style='font-size:12px;'>{$bankLetter}</span>";

                    $html .= "
                    <div class='p-2 mb-2 border rounded-3 d-flex align-items-start gap-2 text-start {$optClass}' style='text-align:left !important;'>
                        <div class='d-flex align-items-center gap-1 flex-shrink-0 pt-1'>
                            {$letterBadge}
                        </div>
                        <div class='flex-grow-1 text-start' style='text-align:left !important;'>{$optText}</div>
                        <div class='flex-shrink-0'>{$badgeChoice}</div>
                    </div>";
                }
                $html .= "</div>";

            } elseif ($type === 'benar_salah') {
                $studentBS = explode(',', $studentUpper);
                $correctBS = explode(',', $correct);

                $html .= "
                <div class='table-responsive mt-2 text-start'>
                    <table class='table table-bordered table-sm align-middle mb-0 text-start' style='font-size:13px; text-align:left;'>
                        <thead class='table-light'>
                            <tr>
                                <th style='width:40px;' class='text-center'>No</th>
                                <th class='text-start'>Pernyataan</th>
                                <th class='text-center' style='width:120px;'>Pilihan Siswa</th>
                                <th class='text-center' style='width:120px;'>Kunci Jawaban</th>
                                <th class='text-center' style='width:100px;'>Status</th>
                            </tr>
                        </thead>
                        <tbody>";

                foreach ($displayOpts as $idxBS => $optItem) {
                    $noBS = $idxBS + 1;
                    $sChoice = $studentBS[$idxBS] ?? '-';
                    $kChoice = $correctBS[$idxBS] ?? '-';

                    $sLabel = match(strtoupper(trim($sChoice))) {
                        'B', 'TRUE', '1'  => "<span class='badge bg-success-subtle text-success border border-success'>BENAR</span>",
                        'S', 'FALSE', '0' => "<span class='badge bg-danger-subtle text-danger border border-danger'>SALAH</span>",
                        default           => "<span class='badge bg-light text-muted border'>-</span>"
                    };

                    $kLabel = match(strtoupper(trim($kChoice))) {
                        'B', 'TRUE', '1'  => "<span class='badge bg-success'>BENAR</span>",
                        'S', 'FALSE', '0' => "<span class='badge bg-danger'>SALAH</span>",
                        default           => "<span class='badge bg-secondary'>-</span>"
                    };

                    $isBSMatch = (strtoupper(trim($sChoice)) === strtoupper(trim($kChoice)));
                    $statusBS = $isBSMatch
                        ? "<span class='text-success fw-bold'><i class='fas fa-check me-1'></i>Benar</span>"
                        : "<span class='text-danger fw-bold'><i class='fas fa-times me-1'></i>Salah</span>";

                    $html .= "
                    <tr>
                        <td class='text-center fw-bold'>{$noBS}</td>
                        <td class='text-start'>{$optItem['text']}</td>
                        <td class='text-center'>{$sLabel}</td>
                        <td class='text-center'>{$kLabel}</td>
                        <td class='text-center'>{$statusBS}</td>
                    </tr>";
                }

                $html .= "</tbody></table></div>";

            } elseif ($type === 'esai') {
                $studentEssayText = nl2br(esc($studentRaw ?: '(Belum dijawab oleh siswa)'));
                $html .= "
                <div class='mt-2 text-start' style='text-align:left !important;'>
                    <label class='form-label fw-bold text-muted small text-start'>Jawaban Siswa:</label>
                    <div class='p-3 bg-light border rounded-3 text-dark text-start' style='min-height:70px; line-height:1.6; text-align:left !important;'>
                        {$studentEssayText}
                    </div>
                </div>";
            }

            $html .= "
                </div>
            </div>";
        }

        return $html;
    }

/**
     * 🛑 Paksa menyelesaikan ujian siswa
     */
    public function forceFinish($sessionId)
    {
        // Log the request for debugging
        log_message('info', '[ForceFinish] Request received for session: ' . $sessionId);

        // Validate if this is an AJAX request
        if (!$this->request->isAJAX()) {
            log_message('error', '[ForceFinish] Not an AJAX request');
            return $this->response->setJSON(['error' => 'Invalid request'])->setStatusCode(400);
        }

        try {
            $session = $this->sessionModel->find($sessionId);
            if (!$session) {
                log_message('error', '[ForceFinish] Session not found: ' . $sessionId);
                return $this->response->setJSON(['error' => 'Sesi tidak ditemukan'])->setStatusCode(404);
            }

            // Check if already finished
            if ($session['status'] === 'finished') {
                log_message('warning', '[ForceFinish] Session already finished: ' . $sessionId);
                return $this->response->setJSON(['error' => 'Sesi sudah selesai'])->setStatusCode(400);
            }

            $studentId = $session['student_id'];
            $testId = $session['test_id'];

            log_message('info', '[ForceFinish] Processing - Student: ' . $studentId . ', Test: ' . $testId);

            $db = \Config\Database::connect();
            $db->transStart();
            // 🔹 Ambil info tes untuk dapat bank_id
            $testStatus = $this->testModel->find($testId);
            if (!$testStatus) {
                log_message('error', '[ForceFinish] Test status not found: ' . $testId);
                $db->transRollback();
                return $this->response->setJSON(['error' => 'Data ujian tidak ditemukan'])->setStatusCode(404);
            }
             // ✅ RELIABLE: Use centralized scoring service
             $res = $this->scoringService->getDetailedScoreResults($studentId, $testId);
             
             if (isset($res['error'])) {
                 $db->transRollback();
                 return $this->response->setJSON(['error' => 'Gagal menghitung nilai: ' . $res['error']])->setStatusCode(500);
             }

             $finalScore = $res['final_score'];
             $essayScore = $res['stats']['esai']['raw_score']; // Might be NULL

             log_message('info', '[ForceFinish] Calculated score: ' . $finalScore);

             // 🔹 Update sesi: tandai selesai dan kunci
             $updateData = [
                 'status' => 'finished',
                 'finished_at' => time(),
                 'score' => $finalScore,
                 'total_score' => $finalScore,
                 'essay_score' => $essayScore,
                 'last_activity' => time(),
                 'cheat_locked' => 1, // siswa tidak bisa simpan jawaban lagi
             ];

            log_message('info', '[ForceFinish] Updating session with data: ' . json_encode($updateData));

            $this->sessionModel->update($sessionId, $updateData);

            $db->transComplete();

            if ($db->transStatus() === false) {
                log_message('error', '[ForceFinish] Transaction failed for session: ' . $sessionId);
                return $this->response->setJSON([
                    'error' => 'Gagal memaksa penyelesaian ujian'
                ])->setStatusCode(500);
            }

            log_message('info', '[ForceFinish] Success! Session: ' . $sessionId . ', Score: ' . $finalScore);

            return $this->response->setJSON([
                'success' => true,
                'session_id' => $sessionId,
                'score' => $finalScore,
                'message' => 'Sesi ujian berhasil dipaksa selesai dan nilai telah dihitung otomatis.',
            ]);

        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ForceFinish] Exception: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
            log_message('error', '[ForceFinish] Stack trace: ' . $e->getTraceAsString());
            return $this->response->setJSON([
                'error' => 'Terjadi kesalahan: ' . $e->getMessage()
            ])->setStatusCode(500);
        }
    }


    /**
     * 🏁 Selesaikan secara paksa SEMUA siswa yang masih 'active' di tes tertentu
     */
    public function forceFinishMassal($testId)
    {
        $testStatus = $this->testModel->find($testId);
        if (!$testStatus) {
            return $this->response->setJSON(['error' => 'Data ujian tidak ditemukan'])->setStatusCode(404);
        }

        $sessions = $this->sessionModel
            ->where('test_id', $testId)
            ->where('status', 'active')
            ->findAll();

        if (empty($sessions)) {
            return $this->response->setJSON(['error' => 'Tidak ada siswa yang sedang aktif ujian.'])->setStatusCode(404);
        }

        $db = \Config\Database::connect();
        $successCount = 0;
        $errors = [];

        foreach ($sessions as $session) {
            $db->transStart();
            try {
                $sessionId = $session['id'];
                $studentId = $session['student_id'];

                // ✅ RELIABLE: Use centralized scoring service
                $res = $this->scoringService->getDetailedScoreResults($studentId, $testId);

                if (isset($res['error'])) {
                    throw new \Exception($res['error']);
                }

                $finalScore = $res['final_score'];
                $essayScore = $res['stats']['esai']['raw_score'];

                $this->sessionModel->update($sessionId, [
                    'status' => 'finished',
                    'finished_at' => time(),
                    'score' => $finalScore,
                    'total_score' => $finalScore,
                    'essay_score' => $essayScore,
                    'cheat_locked' => 1
                ]);

                $db->transComplete();
                if ($db->transStatus() !== false)
                    $successCount++;
                else
                    $errors[] = "Sesi #{$sessionId} gagal : transaction error";

            } catch (\Throwable $e) {
                $db->transRollback();
                $errors[] = "Sesi #{$session['id']} error: " . $e->getMessage();
            }
        }

        return $this->response->setJSON([
            'success' => true,
            'message' => "{$successCount} siswa berhasil diselesaikan secara paksa.",
            'errors' => $errors
        ]);
    }


    /**
     * ⏰ Tambah waktu ujian siswa
     */
    public function addTime($sessionId)
    {
        $minutes = (int) $this->request->getPost('minutes');
        if ($minutes <= 0)
            return $this->response->setJSON(['error' => 'Waktu tidak valid']);

        $session = $this->sessionModel->find($sessionId);
        if (!$session)
            return $this->response->setJSON(['error' => 'Sesi tidak ditemukan']);

        // Check test end time constraint
        $test = $this->testModel->find($session['test_id']);
        if ($test && !empty($test['end_time'])) {
            // Calculate current theoretical end time
            $durationSec = ((int) $test['duration']) * 60;
            $extraSec = ((int) ($session['extra_time'] ?? 0)) * 60;
            $startTs = (int) $session['started_at'];

            $currentEnd = $startTs + $durationSec + $extraSec;
            $newEnd = $currentEnd + ($minutes * 60);

            $testEndTs = strtotime($test['end_time']);

            if ($newEnd > $testEndTs) {
                // Return error if exceeds
                $maxMinutes = floor(($testEndTs - $currentEnd) / 60);
                if ($maxMinutes <= 0) {
                    return $this->response->setJSON(['error' => 'Tidak bisa tambah waktu. Sudah melewati batas akhir ujian (' . $test['end_time'] . ')']);
                }
                return $this->response->setJSON(['error' => "Hanya bisa menambah maksimal $maxMinutes menit karena batas waktu ujian."]);
            }
        }

        // Update extra_time
        $currentExtra = (int) ($session['extra_time'] ?? 0);
        $this->sessionModel->update($sessionId, [
            'extra_time' => $currentExtra + $minutes
        ]);

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * 🔄 Reset sesi ujian agar siswa bisa mengulang
     */
    public function resetSession($sessionId)
    {
        // 🔹 Cari sesi berdasarkan ID
        $session = $this->sessionModel->find($sessionId);
        if (!$session) {
            return $this->response->setJSON(['error' => 'Sesi tidak ditemukan'])
                ->setStatusCode(404);
        }

        // 🔹 (Opsional) Validasi hanya sesi tertentu yang bisa direset
        // Izinkan status: active, finished, completed, expired, inactive
        if (!in_array($session['status'], ['active', 'finished', 'completed', 'expired', 'inactive'])) {
            return $this->response->setJSON(['error' => 'Status sesi tidak valid untuk direset (' . $session['status'] . ')'])
                ->setStatusCode(400);
        }

        $studentId = $session['student_id'];
        $testId = $session['test_id'];

        // 🔹 Gunakan transaksi agar aman bila salah satu query gagal
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            // 1️⃣ Count existing data (for logging)
            $existingAnswers = $this->answerModel
                ->where('student_id', $studentId)
                ->where('test_id', $testId)
                ->countAllResults(false);

            $existingSessions = $this->sessionModel
                ->where('student_id', $studentId)
                ->where('test_id', $testId)
                ->countAllResults(false);

            $existingLogs = $db->table('cbt_cheat_logs')
                ->where('student_id', $studentId)
                ->where('test_id', $testId)
                ->countAllResults(false);

            log_message('info', "[ResetSession] BEFORE: student={$studentId}, test={$testId}, sessions={$existingSessions}, answers={$existingAnswers}, logs={$existingLogs}");

            // 2️⃣ Delete answers
            $deletedAnswers = $this->answerModel
                ->where('student_id', $studentId)
                ->where('test_id', $testId)
                ->delete();

            log_message('info', "[ResetSession] Deleted {$deletedAnswers} answers");

            // 3️⃣ Delete cheat logs
            $deletedLogs = $db->table('cbt_cheat_logs')
                ->where('student_id', $studentId)
                ->where('test_id', $testId)
                ->delete();

            log_message('info', "[ResetSession] Deleted {$deletedLogs} cheat logs");

            // 4️⃣ Delete ALL sessions (not just one)
            $deletedSessions = $this->sessionModel
                ->where('student_id', $studentId)
                ->where('test_id', $testId)
                ->delete();

            log_message('info', "[ResetSession] Deleted {$deletedSessions} sessions");

            // 5️⃣ Verify deletion
            $remainingSessions = $this->sessionModel
                ->where('student_id', $studentId)
                ->where('test_id', $testId)
                ->countAllResults();

            if ($remainingSessions > 0) {
                log_message('error', "[ResetSession] FAILED: {$remainingSessions} sessions still exist!");
                $db->transRollback();
                return $this->response->setJSON(['error' => 'Gagal menghapus session (masih ada ' . $remainingSessions . ' session tersisa)'])
                    ->setStatusCode(500);
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                log_message('error', "[ResetSession] Transaction failed");
                return $this->response->setJSON(['error' => 'Gagal mereset sesi ujian (transaction failed)'])
                    ->setStatusCode(500);
            }

            // 6️⃣ Clear cache
            cache()->delete("cbt_test_info_{$testId}");
            cache()->delete("student_data_{$studentId}");
            cache()->delete("cbt_test_detail_{$testId}");

            // Get bank_id from test for cache clearing
            $test = $this->testModel->find($testId);
            if ($test && !empty($test['bank_id'])) {
                cache()->delete("cbt_questions_bank_" . $test['bank_id']);
                cache()->delete("cbt_all_questions_bank_" . $test['bank_id']);
            }

            log_message('info', "[ResetSession] SUCCESS: student={$studentId}, test={$testId}");

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Sesi ujian berhasil dihapus. Siswa dapat memulai ujian dari awal.',
                'deleted' => [
                    'sessions' => $deletedSessions,
                    'answers' => $deletedAnswers,
                    'cheat_logs' => $deletedLogs
                ]
            ]);

        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', "[ResetSession] Exception: " . $e->getMessage());
            return $this->response->setJSON(['error' => 'Terjadi kesalahan: ' . $e->getMessage()])
                ->setStatusCode(500);
        }
    }

    /**
     * 🔓 Reset login session (active_session_id & last_activity) for a student
     * Used so the student can immediately login from another browser/device
     */
    public function resetLogin($studentId)
    {
        // Require AJAX
        if (!$this->request->isAJAX()) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'Invalid request']);
        }

        $testId = $this->request->getPost('test_id');

        try {
            $db = \Config\Database::connect();

            // 1️⃣ SOFT RESET SESSION: Ubah status kembali ke active agar bisa lanjut
            if (!empty($testId)) {
                $db->table('cbt_sessions')
                    ->where('student_id', $studentId)
                    ->where('test_id', $testId)
                    ->update([
                        'status' => 'active',
                        'finished_at' => null,
                        'score' => null,
                        'total_score' => null,
                        'essay_score' => null,
                        'cheat_locked' => 0,
                        'last_activity' => time()
                    ]);
                
                log_message('info', "[ResetLogin] Session unlocked/soft-reset for student={$studentId}, test={$testId}");
                cache()->delete("cbt_test_detail_{$testId}");
            }

            // 2️⃣ CLEAR DEVICE LOCK: Hapus session ID di tabel users
            // Find user where related_id = studentId and role_id = 3 (Student)
            $user = $db->table('users')
                ->where('related_id', $studentId)
                ->where('role_id', 3)
                ->get()
                ->getRowArray();

            if (!$user) {
                // Jika user tidak ditemukan, mungkin hanya sesi yang perlu di-reset
                return $this->response->setJSON([
                    'success' => true,
                    'message' => 'Sesi ujian telah dibuka kembali. (User account not found for device reset)'
                ]);
            }

            // Update user table
            $db->table('users')->where('id', $user['id'])->update([
                'active_session_id' => null,
                'last_activity' => null
            ]);

            log_message('info', "[ResetLogin] Login block cleared: user={$user['id']}, student={$studentId}");

            return $this->response->setJSON([
                'success' => true,
                'message' => 'Status ujian berhasil dibuka kembali. Siswa dapat login dan melanjutkan ujian tanpa kehilangan jawaban.'
            ]);
        } catch (\Throwable $e) {
            log_message('error', "[ResetLogin] Exception: " . $e->getMessage());
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Terjadi kesalahan internal: ' . $e->getMessage()]);
        }
    }
    /**
     * 🔄 Reset sessions for a specific test (Full or Selective)
     */
    public function resetMassal($testId)
    {
        // 🔹 FIX: Get array from POST (handles both session_ids and session_ids[])
        $sessionIds = $this->request->getPost('session_ids');
        
        // If not found, try with [] suffix (jQuery traditional format)
        if (empty($sessionIds)) {
            $sessionIds = $this->request->getPost('session_ids[]');
        }

        // 🔹 Use transaction for safety
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            if (!empty($sessionIds) && is_array($sessionIds)) {
                // --- SELECTIVE RESET ---

                // 1) Get student IDs associated with these sessions to clean answers/logs
                $sessions = $this->sessionModel
                    ->select('student_id')
                    ->whereIn('id', $sessionIds)
                    ->where('test_id', $testId)
                    ->findAll();

                $studentIds = array_column($sessions, 'student_id');

                if (empty($studentIds)) {
                    $db->transRollback();
                    return $this->response->setJSON(['error' => 'Sesi terpilih tidak ditemukan'])->setStatusCode(404);
                }

                // 2) Delete answers
                $deletedAnswers = $this->answerModel
                    ->whereIn('student_id', $studentIds)
                    ->where('test_id', $testId)
                    ->delete();

                // 3) Delete cheat logs
                $deletedLogs = $db->table('cbt_cheat_logs')
                    ->whereIn('student_id', $studentIds)
                    ->where('test_id', $testId)
                    ->delete();

                // 4) Delete sessions
                $deletedSessions = $this->sessionModel
                    ->whereIn('id', $sessionIds)
                    ->where('test_id', $testId)
                    ->delete();

                $message = count($sessionIds) . ' sesi terpilih berhasil direset.';

            } else {
                // --- FULL RESET (ALL) ---

                // 1) Delete answers
                $deletedAnswers = $this->answerModel
                    ->where('test_id', $testId)
                    ->delete();

                // 2) Delete cheat logs
                $deletedLogs = $db->table('cbt_cheat_logs')
                    ->where('test_id', $testId)
                    ->delete();

                // 3) Delete sessions
                $deletedSessions = $this->sessionModel
                    ->where('test_id', $testId)
                    ->delete();

                $message = 'Seluruh sesi ujian berhasil direset.';
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                log_message('error', "[ResetMassal] Transaction failed");
                return $this->response->setJSON(['error' => 'Gagal mereset sesi ujian (transaction failed)'])
                    ->setStatusCode(500);
            }

            // 5️⃣ Clear cache
            cache()->delete("cbt_test_info_{$testId}");
            cache()->delete("cbt_test_detail_{$testId}");

            // Get bank_id from test for cache clearing
            $test = $this->testModel->find($testId);
            if ($test && !empty($test['bank_id'])) {
                cache()->delete("cbt_questions_bank_" . $test['bank_id']);
                cache()->delete("cbt_all_questions_bank_" . $test['bank_id']);
            }

            return $this->response->setJSON([
                'success' => true,
                'message' => $message . ' Siswa dapat mengulang dari awal.',
                'deleted' => [
                    'sessions' => $deletedSessions,
                    'answers' => $deletedAnswers,
                    'cheat_logs' => $deletedLogs
                ]
            ]);

        } catch (\Exception $e) {
            $db->transRollback();
            log_message('error', "[ResetMassal] Exception: " . $e->getMessage());
            return $this->response->setJSON(['error' => 'Terjadi kesalahan: ' . $e->getMessage()])
                ->setStatusCode(500);
        }
    }



    public function belumTes($testId)
    {
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');

        try {
            $db = $this->db;
            $testId = (int) $testId;

            $test = $db->table('cbt_test_status ts')
                ->select('ts.id, ts.class_codes, ts.bank_id, ts.duration, ts.end_time, ts.subject_type, ts.religion AS test_religion, s.name AS subject_name, s.religion AS subject_religion')
                ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
                ->join('subjects s', 's.id = qb.subject_id', 'left')
                ->where('ts.id', $testId)
                ->get()
                ->getRowArray();

            if (!$test) {
                return $this->response->setBody('<div class="alert alert-danger py-3"><i class="fas fa-exclamation-triangle me-2"></i> Ujian dengan ID ini tidak ditemukan di database.</div>');
            }

            if (method_exists($this, 'autoCloseExpiredSessions')) {
                $this->autoCloseExpiredSessions(
                    $testId,
                    (int) ($test['duration'] ?? 0),
                    (string) ($test['end_time'] ?? '')
                );
            }

            $rawCodes = $test['class_codes'] ?? '';
            $classList = [];
            if (is_string($rawCodes)) {
                $decoded = json_decode($rawCodes, true);
                if (is_array($decoded)) {
                    $classList = $decoded;
                } elseif (trim($rawCodes) !== '') {
                    $classList = array_map('trim', explode(',', $rawCodes));
                }
            } elseif (is_array($rawCodes)) {
                $classList = $rawCodes;
            }

            $classList = array_values(array_filter(array_map('trim', (array) $classList)));
            if (empty($classList)) {
                return $this->response->setBody('<div class="alert alert-warning py-3"><i class="fas fa-info-circle me-2"></i> Tidak ada kelas yang terdaftar untuk ujian ini.</div>');
            }

            $targetReligion = '';
            if (function_exists('cbt_get_test_target_religion')) {
                $targetReligion = cbt_get_test_target_religion($test);
            } elseif (strtolower($test['subject_type'] ?? '') === 'agama') {
                $targetReligion = trim($test['subject_religion'] ?? $test['test_religion'] ?? '');
            }

            // Ambil siswa aktif: join classes langsung dari students.class_id (struktur CBTku)
            $studentQuery = $db->table('students st')
                ->select('st.id, st.name AS student_name, st.nis, st.religion, c.name AS class_name')
                ->join('classes c', 'c.id = st.class_id', 'left')
                ->whereIn('c.name', $classList)
                ->orderBy('c.name', 'ASC')
                ->orderBy('st.name', 'ASC')
                ->get()
                ->getResultArray();

            // Deduplikasi siswa di PHP
            $seenStudentIds = [];
            $students = [];
            foreach ($studentQuery as $row) {
                $sid = (int) ($row['id'] ?? 0);
                if ($sid > 0 && !isset($seenStudentIds[$sid])) {
                    $seenStudentIds[$sid] = true;
                    $students[] = $row;
                }
            }

            // Filter agama jika mapel agama
            if (!empty($targetReligion)) {
                $targetRelLower = strtolower(trim($targetReligion));
                $students = array_values(array_filter($students, function ($s) use ($targetRelLower) {
                    $rel = strtolower(trim($s['religion'] ?? ''));
                    return $rel === $targetRelLower || (function_exists('cbt_normalize_religion') && cbt_normalize_religion($rel) === $targetRelLower);
                }));
            }

            // 1. Ambil student_id yang tercatat di cbt_sessions
            $doneSessions = $db->table('cbt_sessions')
                ->select('student_id')
                ->where('test_id', $testId)
                ->get()
                ->getResultArray();

            // 2. Ambil student_id yang tercatat di cbt_answers jika tabel ada
            $doneAnswers = [];
            if ($db->tableExists('cbt_answers')) {
                $doneAnswers = $db->table('cbt_answers')
                    ->select('student_id')
                    ->where('test_id', $testId)
                    ->get()
                    ->getResultArray();
            }

            $doneSessionIds = array_map('intval', array_column($doneSessions, 'student_id'));
            $doneAnswerIds  = array_map('intval', array_column($doneAnswers, 'student_id'));
            $allDoneIds     = array_unique(array_filter(array_merge($doneSessionIds, $doneAnswerIds)));
            $doneMap        = array_fill_keys($allDoneIds, true);

            $notTested = array_values(array_filter($students, function ($s) use ($doneMap) {
                return !isset($doneMap[(int) $s['id']]);
            }));

            $totalStudents = count($students);
            $totalNotTested = count($notTested);
            $totalDone = max(0, $totalStudents - $totalNotTested);
            $pctDone = $totalStudents > 0 ? round(($totalDone / $totalStudents) * 100, 1) : 0;
            $syncTime = date('H:i:s');

            $religionNotice = '';
            if (!empty($targetReligion)) {
                $relLabel = ucfirst($targetReligion);
                $religionNotice = "<div class='alert alert-info py-2 px-3 mb-3 small d-flex align-items-center gap-2'>
                    <i class='fas fa-info-circle fa-lg text-primary'></i>
                    <div>
                        Khusus Mata Pelajaran <strong>" . esc($test['subject_name'] ?? 'Agama') . "</strong>: 
                        Hanya menampilkan siswa beragama <strong>{$relLabel}</strong>. Siswa dengan agama berbeda tidak diwajibkan mengikuti ujian ini.
                    </div>
                </div>";
            }

            if (empty($students)) {
                return $this->response->setBody($religionNotice . '<div class="alert alert-warning py-3"><i class="fas fa-exclamation-circle me-2"></i> Tidak ada siswa ' . (!empty($targetReligion) ? 'beragama ' . ucfirst($targetReligion) . ' ' : '') . 'pada kelas terkait ujian ini.</div>');
            }

            // Statistik Cards
            $statsHtml = "
            <div class='row g-2 mb-3'>
                <div class='col-sm-4'>
                    <div class='card border-0 bg-light-subtle shadow-sm rounded-3 p-2 text-center'>
                        <small class='text-muted d-block'>Target Siswa</small>
                        <span class='fs-5 fw-bold text-dark'><i class='fas fa-users me-1 text-secondary'></i> {$totalStudents}</span>
                    </div>
                </div>
                <div class='col-sm-4'>
                    <div class='card border-0 bg-success-subtle shadow-sm rounded-3 p-2 text-center'>
                        <small class='text-success fw-semibold d-block'>Sudah Ikut Tes</small>
                        <span class='fs-5 fw-bold text-success'><i class='fas fa-user-check me-1'></i> {$totalDone} <small class='fs-6 fw-normal'>({$pctDone}%)</small></span>
                    </div>
                </div>
                <div class='col-sm-4'>
                    <div class='card border-0 " . ($totalNotTested > 0 ? "bg-warning-subtle" : "bg-light-subtle") . " shadow-sm rounded-3 p-2 text-center'>
                        <small class='" . ($totalNotTested > 0 ? "text-danger" : "text-muted") . " fw-semibold d-block'>Belum Tes</small>
                        <span class='fs-5 fw-bold " . ($totalNotTested > 0 ? "text-danger" : "text-muted") . "'><i class='fas fa-user-times me-1'></i> {$totalNotTested}</span>
                    </div>
                </div>
            </div>
            <div class='progress mb-3' style='height: 6px;'>
                <div class='progress-bar bg-success progress-bar-striped progress-bar-animated' role='progressbar' style='width: {$pctDone}%' aria-valuenow='{$pctDone}' aria-valuemin='0' aria-valuemax='100'></div>
            </div>";

            if (empty($notTested)) {
                return $this->response->setBody(
                    $religionNotice . $statsHtml .
                    '<div class="alert alert-success d-flex align-items-center gap-3 p-3 shadow-sm rounded-3 mb-0">
                        <i class="fas fa-check-circle fa-2x text-success"></i>
                        <div>
                            <h6 class="fw-bold mb-1">Semua Siswa Telah Mengikuti Tes 🎉</h6>
                            <small class="text-muted">Seluruh <strong>' . $totalStudents . ' siswa</strong> ' . (!empty($targetReligion) ? 'beragama ' . ucfirst($targetReligion) . ' ' : '') . 'telah mulai atau menyelesaikan ujian ini.</small>
                        </div>
                    </div>'
                );
            }

            // Toolbar pencarian dan sinkronisasi
            $toolbarHtml = "
            <div class='d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2'>
                <div class='d-flex align-items-center gap-2'>
                    <span class='badge bg-light text-dark border py-1 px-2 small'>
                        <i class='fas fa-clock text-primary me-1'></i> Sinkron: {$syncTime} WIB
                    </span>
                    <span class='badge bg-warning-subtle text-dark border border-warning-subtle py-1 px-2 small'>
                        <i class='fas fa-user-times text-danger me-1'></i> {$totalNotTested} Siswa Belum Tes
                    </span>
                </div>
                <div style='min-width: 200px; max-width: 280px;' class='flex-grow-1 flex-sm-grow-0'>
                    <div class='input-group input-group-sm'>
                        <span class='input-group-text bg-light'><i class='fas fa-search text-muted'></i></span>
                        <input type='text' id='searchBelumTes' class='form-control form-control-sm' placeholder='Cari nama atau kelas...'>
                    </div>
                </div>
            </div>";

            $tableHtml = $religionNotice . $statsHtml . $toolbarHtml . "
            <div class='table-responsive border rounded-3 shadow-sm'>
                <table class='table table-hover table-striped table-sm align-middle mb-0' id='tableBelumTes'>
                    <thead class='table-light text-center border-bottom'>
                        <tr>
                            <th style='width: 45px;' class='py-2'>No</th>
                            <th class='text-start py-2'>Nama Siswa</th>
                            <th style='width: 120px;' class='py-2'>Kelas</th>" .
                            (!empty($targetReligion) ? "<th style='width: 110px;' class='py-2'>Agama</th>" : "") . "
                            <th style='width: 130px;' class='py-2'>Status</th>
                        </tr>
                    </thead>
                    <tbody>";

            $i = 1;
            foreach ($notTested as $row) {
                $nisInfo = !empty($row['nis']) ? "<span class='text-muted small ms-1'>(" . esc($row['nis']) . ")</span>" : "";
                $tableHtml .= "<tr class='belum-tes-row'>
                    <td class='text-center text-muted fw-semibold'>{$i}</td>
                    <td class='text-start'>
                        <div class='fw-semibold text-dark'>" . esc($row['student_name']) . "</div>
                        {$nisInfo}
                    </td>
                    <td class='text-center'>
                        <span class='badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1'>" . esc($row['class_name']) . "</span>
                    </td>" .
                    (!empty($targetReligion) ? "<td class='text-center'><span class='badge bg-light text-dark border px-2 py-1'>" . esc($row['religion'] ?: '-') . "</span></td>" : "") . "
                    <td class='text-center'>
                        <span class='badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1'>
                            <i class='fas fa-times-circle me-1'></i> Belum Tes
                        </span>
                    </td>
                </tr>";
                $i++;
            }

            $tableHtml .= "
                    <tr id='noSearchResultRow' style='display: none;'>
                        <td colspan='" . (!empty($targetReligion) ? "5" : "4") . "' class='text-center text-muted py-4'>
                            <i class='fas fa-search me-1'></i> Tidak ditemukan siswa yang cocok dengan pencarian.
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>";

            return $this->response->setBody($tableHtml);
        } catch (\Throwable $e) {
            log_message('error', '[CbtAktivitas::belumTes] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $errorMsg = esc($e->getMessage());
            return $this->response->setStatusCode(200)->setBody("
                <div class='alert alert-danger py-3 shadow-sm'>
                    <div class='d-flex align-items-center gap-2 mb-2'>
                        <i class='fas fa-exclamation-triangle fa-lg text-danger'></i>
                        <strong class='fs-6'>Terjadi Kendala Memuat Data Siswa</strong>
                    </div>
                    <p class='small text-muted mb-2'>Pesan sistem: <code>{$errorMsg}</code></p>
                    <button type='button' class='btn btn-sm btn-outline-danger' onclick='if(typeof fetchBelumTes===\"function\"&&currentBelumTesTestId){fetchBelumTes(currentBelumTesTestId,false);}'>
                        <i class='fas fa-sync-alt me-1'></i> Coba Muat Ulang
                    </button>
                </div>
            ");
        }
    }

public function unduhNilai($testId)
    {
        $classFilter = $this->request->getGet('class') ?? 'all';
        $db = $this->db;

        $test = $db->table('cbt_test_status ts')
            ->select('ts.id, qb.code AS bank_code, s.name AS subject_name, en.name AS exam_name')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = ts.exam_name_id', 'left')
            ->where('ts.id', $testId)
            ->get()
            ->getRowArray();

        if (!$test) {
            return redirect()->back()->with('error', 'Data ujian tidak ditemukan.');
        }

        // FIX: Handle duplicate sessions - only get latest session per student using MAX(id)
        // Build subquery first to ensure we only get latest session per student
        $subquery = '(
            SELECT student_id, MAX(id) as max_session_id
            FROM cbt_sessions
            WHERE test_id = ' . (int) $testId;

        // Apply class filter in subquery if needed
        if ($classFilter !== 'all') {
            $subquery .= ' AND student_id IN (
                SELECT id FROM students WHERE class_id = ' . (int) $classFilter . '
            )';
        }

        $subquery .= ' GROUP BY student_id
        ) latest';

        $builder = $db->table('cbt_sessions cs')
            ->select('cs.student_id, st.name AS student_name, st.nis, c.name AS class_name, 
                      COALESCE(cs.total_score, cs.score, 0) as final_score, 
                      cs.status, cs.finished_at')
            ->join('students st', 'st.id = cs.student_id', 'left')
            ->join('classes c', 'c.id = st.class_id', 'left')
            ->join($subquery, 'cs.id = latest.max_session_id', 'inner')
            ->where('cs.test_id', $testId)
            ->orderBy('c.name', 'ASC')
            ->orderBy('st.name', 'ASC');

        $data = $builder->get()->getResultArray();

        // DEBUG: Check for duplicate student_ids
        $studentIds = array_column($data, 'student_id');
        $studentNames = array_column($data, 'student_name');
        $duplicates = array_diff_assoc($studentIds, array_unique($studentIds));

        log_message('info', '[unduhNilai] Test ID: ' . $testId . ', Total rows: ' . count($data));
        log_message('info', '[unduhNilai] Student IDs: ' . json_encode($studentIds));
        log_message('info', '[unduhNilai] Student Names: ' . json_encode($studentNames));

        if (!empty($duplicates)) {
            log_message('error', '[unduhNilai] Found duplicate student_ids in result: ' . json_encode($duplicates));
            log_message('error', '[unduhNilai] Full data: ' . json_encode($data));
        }

        // Get violation counts for all students in this test
        $violationCounts = $db->table('cbt_cheat_logs')
            ->select('student_id, COUNT(*) as violation_count')
            ->where('test_id', $testId)
            ->groupBy('student_id')
            ->get()
            ->getResultArray();

        // Map violation counts by student_id
        $violationMap = [];
        foreach ($violationCounts as $v) {
            $violationMap[$v['student_id']] = $v['violation_count'];
        }

        // Add violation count to each student record (without reference to avoid issues)
        $dataWithViolations = [];
        foreach ($data as $d) {
            $d['violation_count'] = $violationMap[$d['student_id']] ?? 0;
            $dataWithViolations[] = $d;
        }
        $data = $dataWithViolations;

        if (empty($data)) {
            return redirect()->back()->with('error', 'Tidak ada data nilai yang ditemukan.');
        }

        // === Generate Spreadsheet (tidak berubah) ===
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'REKAP NILAI UJIAN');
        $sheet->setCellValue('A2', $test['exam_name'] . ' - ' . $test['subject_name'] . ' [' . $test['bank_code'] . ']');
        $sheet->mergeCells('A1:G1');
        $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A1:A2')->getFont()->setBold(true);

        $sheet->fromArray(['No', 'NIS', 'Nama Siswa', 'Kelas', 'Nilai', 'Status', 'Pelanggaran'], null, 'A4');
        $sheet->getStyle('A4:G4')->getFont()->setBold(true);
        $sheet->getStyle('A4:G4')->getAlignment()->setHorizontal('center');

        $row = 5;
        $no = 1;
        foreach ($data as $d) {
            $sheet->setCellValue("A{$row}", $no++);
            $sheet->setCellValue("B{$row}", $d['nis'] ?? '-');
            $sheet->setCellValue("C{$row}", $d['student_name']);
            $sheet->setCellValue("D{$row}", $d['class_name']);
            $sheet->setCellValue("E{$row}", $d['final_score']); // Changed from total_score to final_score
            $sheet->setCellValue("F{$row}", strtoupper($d['status']));
            $sheet->setCellValue("G{$row}", $d['violation_count']);
            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->getStyle('A5:A' . $row)->getAlignment()->setHorizontal('center');
        $sheet->getStyle('B5:B' . $row)->getAlignment()->setHorizontal('center');
        $sheet->getStyle('E5:G' . $row)->getAlignment()->setHorizontal('center');

        $fileNamePrefix = 'Nilai_' . str_replace(' ', '_', $test['subject_name']);
        if ($classFilter !== 'all') {
            $classData = $db->table('classes')->where('id', (int)$classFilter)->get()->getRowArray();
            if ($classData) {
                $fileNamePrefix .= '_' . str_replace(' ', '_', $classData['name']);
            }
        }
        $fileName = $fileNamePrefix . '_' . date('Ymd_His') . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$fileName}\"");
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;
    }


    public function analisisSoal($testId)
    {
        $db = $this->db;

        // ambil info test
        $test = $db->table('cbt_test_status ts')
            ->select('ts.*, qb.code AS bank_code, s.name AS subject_name, en.name AS exam_name')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = ts.exam_name_id', 'left')
            ->where('ts.id', $testId)
            ->get()->getRowArray();

        if (!$test) {
            return redirect()->back()->with('error', 'Data ujian tidak ditemukan.');
        }

        // ambil semua soal di bank
        $questions = $db->table('cbt_questions')
            ->where('bank_id', $test['bank_id'])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        // ambil statistik jawaban untuk PG biasa (berdasarkan test_id)
        $answersStats = $db->table('cbt_answers ca')
            ->select('ca.question_id, COUNT(*) AS total,
                     SUM(CASE WHEN q.correct_option = ca.answer THEN 1 ELSE 0 END) AS benar')
            ->join('cbt_questions q', 'q.id = ca.question_id', 'left')
            ->where('ca.test_id', $testId)
            ->whereIn('q.question_type', ['pg', 'pilihan_ganda', 'multiple_choice'])
            ->groupBy('ca.question_id')
            ->get()
            ->getResultArray();

        $statsMap = [];
        foreach ($answersStats as $r) {
            $statsMap[$r['question_id']] = $r;
        }

        // statistik untuk PG Kompleks & Benar Salah
        $multiAnswers = $db->table('cbt_answers ca')
            ->select('ca.question_id, ca.answer, q.correct_option, q.question_type')
            ->join('cbt_questions q', 'q.id = ca.question_id', 'left')
            ->where('ca.test_id', $testId)
            ->whereIn('q.question_type', ['pg_kompleks', 'benar_salah'])
            ->get()
            ->getResultArray();

        $multiStats = [];
        foreach ($multiAnswers as $ans) {
            $qid = $ans['question_id'];
            $type = $ans['question_type'];
            if (!isset($multiStats[$qid])) {
                $multiStats[$qid] = ['total' => 0, 'benar' => 0];
            }
            $multiStats[$qid]['total']++;

            $correctStr = $ans['correct_option'] ?? '';
            $studentStr = $ans['answer'] ?? '';

            if (!empty($correctStr) && !empty($studentStr)) {
                $correctArr = explode(',', $correctStr);
                $studentArr = explode(',', $studentStr);
                $score = $this->scoringService->compareAnswerNormalized($type, $studentStr, $correctStr);

                // Threshold 70% untuk dianggap "benar" di analisis
                if ($score >= 0.7) {
                    $multiStats[$qid]['benar']++;
                }
            }
        }

        foreach ($multiStats as $qid => $stat) {
            $statsMap[$qid] = $stat;
        }

        $result = [];
        foreach ($questions as $i => $q) {
            $qid = $q['id'];
            $total = (int) ($statsMap[$qid]['total'] ?? 0);
            $benar = (int) ($statsMap[$qid]['benar'] ?? 0);
            $analisis = '-';
            if ($total > 0) {
                $persen = $benar / $total;
                if ($persen <= (1 / 3))
                    $analisis = 'Susah';
                elseif ($persen <= (2 / 3))
                    $analisis = 'Sedang';
                else
                    $analisis = 'Mudah';
            }

            $result[] = [
                'no' => $i + 1,
                'question' => $q['raw_text'] ?? ($q['question_text'] ?? ($q['question'] ?? '')),
                'type' => strtolower($q['question_type'] ?? 'pg'),
                'total' => $total,
                'benar' => $benar,
                'analisis' => $analisis
            ];
        }

        return view('admin/cbt/analisis_soal/index', [
            'test' => $test,
            'data' => $result
        ]);
    }

    public function analisisDownload($testId)
    {
        $db = $this->db;

        // ambil data ujian
        $test = $db->table('cbt_test_status ts')
            ->select('ts.*, qb.code AS bank_code, s.name AS subject_name, en.name AS exam_name')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = ts.exam_name_id', 'left')
            ->where('ts.id', $testId)
            ->get()->getRowArray();

        if (!$test) {
            return redirect()->back()->with('error', 'Data ujian tidak ditemukan.');
        }

        // ambil soal & jawaban
        $questions = $db->table('cbt_questions')->where('bank_id', $test['bank_id'])->orderBy('id', 'ASC')->get()->getResultArray();

        // ambil statistik untuk PG biasa (berdasarkan test_id)
        $answersStats = $db->table('cbt_answers ca')
            ->select('ca.question_id, COUNT(*) AS total, SUM(CASE WHEN q.correct_option = ca.answer THEN 1 ELSE 0 END) AS benar')
            ->join('cbt_questions q', 'q.id = ca.question_id', 'left')
            ->where('ca.test_id', $testId)
            ->whereIn('q.question_type', ['pg', 'pilihan_ganda', 'multiple_choice'])
            ->groupBy('ca.question_id')
            ->get()
            ->getResultArray();

        $statsMap = [];
        foreach ($answersStats as $r)
            $statsMap[$r['question_id']] = $r;

        // statistik untuk PG Kompleks & BS
        $multiAnswers = $db->table('cbt_answers ca')
            ->select('ca.question_id, ca.answer, q.correct_option, q.question_type')
            ->join('cbt_questions q', 'q.id = ca.question_id', 'left')
            ->where('ca.test_id', $testId)
            ->whereIn('q.question_type', ['pg_kompleks', 'benar_salah'])
            ->get()
            ->getResultArray();

        $multiStats = [];
        foreach ($multiAnswers as $ans) {
            $qid = $ans['question_id'];
            $type = $ans['question_type'];
            if (!isset($multiStats[$qid]))
                $multiStats[$qid] = ['total' => 0, 'benar' => 0];
            $multiStats[$qid]['total']++;

            $cStr = $ans['correct_option'] ?? '';
            $sStr = $ans['answer'] ?? '';
            $score = $this->calculateNormalizedScore($type, $ans['answer'], $ans['correct_option']);
            if ($score >= 0.7) {
                $multiStats[$qid]['benar']++;
            }
        }
        foreach ($multiStats as $qid => $stat)
            $statsMap[$qid] = $stat;

        // buat spreadsheet (PG & Esai seperti sebelumnya)
        $spreadsheet = new Spreadsheet();
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Analisis PG');
        $sheet1->setCellValue('A1', 'Analisis Soal Pilihan Ganda');
        $sheet1->setCellValue('A2', $test['exam_name'] . ' - ' . $test['subject_name']);
        $sheet1->mergeCells('A1:E1');
        $sheet1->mergeCells('A2:E2');
        $sheet1->getStyle('A1:A2')->getFont()->setBold(true);

        $sheet1->fromArray(['No', 'Pertanyaan', 'Tipe', 'Partisipan', 'Benar', 'Analisis'], null, 'A4');
        $row = 5;
        $no = 1;
        foreach ($questions as $q) {
            $qType = strtolower($q['question_type'] ?? 'pg');
            if (in_array($qType, ['esai', 'essay']))
                continue;

            $qid = $q['id'];
            $total = (int) ($statsMap[$qid]['total'] ?? 0);
            $benar = (int) ($statsMap[$qid]['benar'] ?? 0);
            $analisis = '-';
            if ($total > 0) {
                $p = $benar / $total;
                if ($p <= (1 / 3))
                    $analisis = 'Susah';
                elseif ($p <= (2 / 3))
                    $analisis = 'Sedang';
                else
                    $analisis = 'Mudah';
            }
            $sheet1->fromArray([
                $no++,
                strip_tags($q['question_text'] ?? ''),
                strtoupper($qType),
                $total,
                $benar,
                $analisis
            ], null, 'A' . $row);
            $row++;
        }

        foreach (range('A', 'F') as $col)
            $sheet1->getColumnDimension($col)->setAutoSize(true);

        // --- Sheet 2: Esai ---
        $spreadsheet->createSheet();
        $sheet2 = $spreadsheet->getSheet(1);
        $sheet2->setTitle('Analisis Esai');
        $sheet2->setCellValue('A1', 'Daftar Soal Esai');
        $sheet2->setCellValue('A2', $test['exam_name'] . ' - ' . $test['subject_name']);
        $sheet2->fromArray(['No', 'Pertanyaan', 'Tipe'], null, 'A4');

        $rowEsai = 5;
        $noEsai = 1;
        foreach ($questions as $q) {
            $qType = strtolower($q['question_type'] ?? 'pg');
            if (!in_array($qType, ['esai', 'essay']))
                continue;

            $sheet2->fromArray([
                $noEsai++,
                strip_tags($q['question_text'] ?? ''),
                'ESAI'
            ], null, 'A' . $rowEsai);
            $rowEsai++;
        }
        foreach (range('A', 'C') as $col)
            $sheet2->getColumnDimension($col)->setAutoSize(true);

        $fileName = 'Analisis_Soal_' . preg_replace('/\s+/', '_', $test['subject_name']) . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    public function downloadAnalisis($testId)
    {
        $mode = $this->request->getGet('mode');
        $classId = $this->request->getGet('class');

        // 🔹 Ambil data tes
        $test = $this->testModel
            ->select('ts.*, qb.code AS bank_code, s.name AS subject_name, en.name AS exam_name')
            ->from('cbt_test_status ts')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = ts.exam_name_id', 'left')
            ->where('ts.id', $testId)
            ->get()
            ->getRowArray();

        if (!$test) {
            return redirect()->back()->with('error', 'Tes tidak ditemukan.');
        }

        // FIX: Handle duplicate sessions - only get latest session per student using MAX(id)
        $subquery = $this->db->table('cbt_sessions')
            ->select('student_id, MAX(id) as max_id')
            ->where('test_id', $testId)
            ->groupBy('student_id')
            ->getCompiledSelect();

        // 🔹 Ambil sesi siswa — langsung join ke classes
        $sessions = $this->sessionModel
            ->select('cs.*, st.name AS student_name, cl.name AS class_name, st.class_id')
            ->from('cbt_sessions cs')
            ->join('students st', 'st.id = cs.student_id', 'left')
            ->join('classes cl', 'cl.id = st.class_id', 'left')
            ->join("($subquery) latest", 'cs.id = latest.max_id', 'inner')
            ->where('cs.test_id', $testId);

        if ($mode === 'kelas' && $classId) {
            $sessions->where('st.class_id', $classId);
        }

        $sessions = $sessions
            ->orderBy('st.name', 'ASC')
            ->get()
            ->getResultArray();

        if (!$sessions) {
            return redirect()->back()->with('error', 'Tidak ada data sesi ditemukan.');
        }

        // 🔹 Ambil semua soal (PG, PGK, BS, Esai)
        $questions = $this->questionModel
            ->where('bank_id', $test['bank_id'])
            ->orderBy('id', 'ASC')
            ->findAll();

        // 🔹 Siapkan header kolom soal
        $headers = [];
        foreach ($questions as $i => $q) {
            $keyStr = $q['correct_option'] ?: '-';
            if (strtolower($q['question_type']) === 'esai')
                $keyStr = 'Esai';

            $headers[] = [
                'no' => $i + 1,
                'key' => str_replace(',', ', ', strtoupper($keyStr))
            ];
        }

        // 🔹 Buat spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Analisis Jawaban');

        // Header informasi umum
        $sheet->mergeCells('A1:Z1')->setCellValue('A1', 'ANALISIS JAWABAN UJIAN BERBASIS KOMPUTER');
        $sheet->mergeCells('A2:Z2')->setCellValue('A2', 'Data Analisis Jawaban Siswa');
        $sheet->setCellValue('A4', 'Kode Tes : ' . $test['bank_code']);
        $sheet->setCellValue('A5', 'Mapel : ' . $test['subject_name']);
        $sheet->setCellValue('A6', 'Ujian : ' . $test['exam_name']);

        // Header tabel
        $headerRow = ['NO', 'NAMA SISWA', 'KELAS', 'BENAR', 'SALAH', 'NILAI'];
        $sheet->fromArray([$headerRow], NULL, 'A7');

        // Tambahkan kolom nomor soal
        $colIndex = 7; // Kolom ke-7 = G
        foreach ($headers as $h) {
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->setCellValue($colLetter . '7', $h['no']);
            $colIndex++;
        }

        // 🔹 Isi data siswa
        $row = 8;
        foreach ($sessions as $i => $s) {
            $answers = $this->answerModel
                ->where('student_id', $s['student_id'])
                ->where('test_id', $testId)
                ->findAll();

            $ansMap = [];
            foreach ($answers as $a) {
                $ansMap[$a['question_id']] = $a['answer']; // Tetap case-sensitive untuk Esai
            }

            $benar = 0;
            $salah = 0;
            $cols = [];

            foreach ($questions as $q) {
                $rawJawaban = $ansMap[$q['id']] ?? '';
                $jawaban = $rawJawaban;
                $qType = strtolower($q['question_type'] ?? 'pg');

                $score = $this->calculateNormalizedScore($qType, $jawaban, $q['correct_option']);
                $isCorrect = ($score >= 0.7);

                if ($isCorrect) {
                    $benar++;
                } else {
                    if ($qType !== 'esai' && $jawaban)
                        $salah++;
                }

                // Tampilkan jawaban di kolom matrix
                if ($qType === 'esai') {
                    $cols[] = $rawJawaban ?: '-';
                } else {
                    $cols[] = $jawaban ?: '-';
                }
            }

            // Gunakan nilai akhir dari database (berbobot)
            $nilai = $s['total_score'] ?? 0;

            $sheet->fromArray([
                [
                    $i + 1,
                    $s['student_name'],
                    $s['class_name'] ?? '-',
                    $benar,
                    $salah,
                    $nilai,
                    ...$cols
                ]
            ], NULL, "A{$row}");

            $row++;
        }

        // ======================
        // 🎨 STYLING TABEL
        // ======================
        $this->applyAnalysisTableStyling($sheet, $row, $headers);

        // ======================
        // 💾 SIMPAN & UNDUH
        // ======================
        $filenamePrefix = 'Analisis_Jawaban_' . $test['bank_code'];
        if ($mode === 'kelas' && $classId) {
            $classData = $this->db->table('classes')->where('id', (int)$classId)->get()->getRowArray();
            if ($classData) {
                $filenamePrefix .= '_' . str_replace(' ', '_', $classData['name']);
            }
        }
        $filename = $filenamePrefix . '.xlsx';
        $exportPath = WRITEPATH . 'exports';

        if (!is_dir($exportPath)) {
            mkdir($exportPath, 0777, true);
        }

        $tempFile = $exportPath . '/' . $filename;
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $this->response
            ->download($tempFile, null)
            ->setFileName($filename)
            ->setContentType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function laporanJawaban($testId)
    {
        $db = $this->db;

        $test = $this->testModel
            ->select('cbt_test_status.*, qb.code AS bank_code, s.name AS subject_name, en.name AS exam_name')
            ->join('cbt_question_banks qb', 'qb.id = cbt_test_status.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = cbt_test_status.exam_name_id', 'left')
            ->where('cbt_test_status.id', $testId)
            ->get()
            ->getRowArray();

        if (!$test) {
            return redirect()->back()->with('error', 'Tes tidak ditemukan.');
        }

        // FIX: Handle duplicate sessions - only get latest session per student using MAX(id)
        $subquery = $db->table('cbt_sessions')
            ->select('student_id, MAX(id) as max_id')
            ->where('test_id', $testId)
            ->groupBy('student_id')
            ->getCompiledSelect();

        // ambil daftar siswa yang mengikuti tes: join langsung students -> classes
        $sessions = $this->sessionModel
            ->select('cbt_sessions.*, st.username AS exam_number, st.name AS student_name, st.nis, st.class_id, cl.name AS class_name')
            ->join('students st', 'st.id = cbt_sessions.student_id', 'left')
            ->join('classes cl', 'cl.id = st.class_id', 'left')
            ->join("($subquery) latest", 'cbt_sessions.id = latest.max_id', 'inner')
            ->where('cbt_sessions.test_id', $testId)
            ->orderBy('st.name', 'ASC')
            ->findAll();

        return view('admin/cbt/aktivitas/laporan_jawaban', [
            'test' => $test,
            'sessions' => $sessions
        ]);
    }

    public function simpanNilaiEsai()
    {
        $sessionId = $this->request->getPost('session_id');
        $nilaiEsai = $this->request->getPost('nilai_esai');

        $this->sessionModel->update($sessionId, ['essay_score' => $nilaiEsai]);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Nilai esai berhasil disimpan'
        ]);
    }

    public function laporanJawabanPdf($testId, $studentId)
    {
        return $this->generateStudentReportPdf($testId, $studentId);
    }

    public function laporanJawabPdf($testId, $studentId)
    {
        return $this->generateStudentReportPdf($testId, $studentId);
    }

    /**
     * 📄 Core logic for generating student result PDF
     * Centralized to ensure consistency across all report routes
     */
    private function generateStudentReportPdf($testId, $studentId)
    {
        $db = db_connect();

        // 🔹 Ambil informasi tes
        $test = $db->table('cbt_test_status ts')
            ->select('ts.*, qb.code AS bank_code, s.name AS subject_name, en.name AS exam_name')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id', 'left')
            ->join('subjects s', 's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en', 'en.id = ts.exam_name_id', 'left')
            ->where('ts.id', $testId)
            ->get()
            ->getRowArray();

        if (!$test) {
            return redirect()->back()->with('error', 'Data tes tidak ditemukan.');
        }

        // 🔹 Ambil data siswa + kelas
        $student = $db->table('students st')
            ->select('st.id, st.name, st.nis, st.class_id, cl.name AS class_name')
            ->join('classes cl', 'cl.id = st.class_id', 'left')
            ->where('st.id', $studentId)
            ->get()
            ->getRowArray();

        if (!$student) {
            return redirect()->back()->with('error', 'Data siswa tidak ditemukan.');
        }

        // ✅ RELIABLE: Use centralized scoring service (consistent everywhere!)
        $res = $this->scoringService->getDetailedScoreResults($studentId, $testId);
        
        if (isset($res['error'])) {
            return redirect()->back()->with('error', 'Gagal hitung nilai: ' . $res['error']);
        }

        $stats = $res['stats'] ?? [];
        $scoringBreakdown = $res['breakdown'] ?? [];
        $finalScore = $res['final_score'];

        // 🔹 Ambil semua jawaban siswa + soal (untuk tampilan PDF)
        $answers = $db->table('cbt_answers a')
            ->select('a.*, q.question_text, q.question_type, q.correct_option, q.raw_text,
                  q.option_a, q.option_b, q.option_c, q.option_d, q.option_e')
            ->join('cbt_questions q', 'q.id = a.question_id', 'left')
            ->where('a.test_id', $testId)
            ->where('a.student_id', $studentId)
            ->orderBy('q.id', 'ASC')
            ->get()
            ->getResultArray();

        $questionData = [];
        foreach ($answers as $a) {
            $qid = $a['question_id'];
            $breakdown = $scoringBreakdown[$qid] ?? null;
            $type = strtolower($a['question_type'] ?? 'pg');
            
            $questionData[] = [
                'id' => $qid,
                'type' => $type,
                'text' => $a['question_text'], 
                'options' => [
                    'A' => $a['option_a'] ?? null,
                    'B' => $a['option_b'] ?? null,
                    'C' => $a['option_c'] ?? null,
                    'D' => $a['option_d'] ?? null,
                    'E' => $a['option_e'] ?? null,
                ],
                'answer' => $a['answer'] ?? '',
                'correct_option' => $a['correct_option'] ?? '',
                'score' => $breakdown['score'] ?? 0,
                'is_correct' => ($breakdown['score'] ?? 0) >= 0.7
            ];
        }

        // 🔹 Urutkan (Konsisten): PG -> PGK -> BS -> Esai
        usort($questionData, function ($a, $b) {
            $order = [
                'pg' => 1, 'pilihan_ganda' => 1, 'multiple_choice' => 1,
                'pgk' => 2, 'pg_kompleks' => 2,
                'bs' => 3, 'benar_salah' => 3,
                'esai' => 4, 'essay' => 4
            ];
            return ($order[$a['type']] ?? 99) <=> ($order[$b['type']] ?? 99);
        });

        // 🔹 Render view ke HTML
        $html = view('admin/cbt/aktivitas/pdf_laporan_jawaban', [
            'test' => $test,
            'student' => $student,
            'questions' => $questionData,

            // PG
            'nilai_pg_raw' => $stats['pg']['score'] ?? 0,
            'bobot_pg' => $stats['pg']['weight'] ?? 0,
            'nilai_pg_akhir' => ($stats['pg']['score'] ?? 0) * ($stats['pg']['weight'] ?? 0) / 100,
            'pg_total' => $stats['pg']['total'] ?? 0,
            'pg_benar' => $stats['pg']['earned'] ?? 0,

            // PGK
            'nilai_pgk_raw' => $stats['pgk']['score'] ?? 0,
            'bobot_pgk' => $stats['pgk']['weight'] ?? 0,
            'nilai_pgk_akhir' => ($stats['pgk']['score'] ?? 0) * ($stats['pgk']['weight'] ?? 0) / 100,
            'pgk_total' => $stats['pgk']['total'] ?? 0,
            'pgk_benar' => $stats['pgk']['earned'] ?? 0,

            // BS
            'nilai_bs_raw' => $stats['bs']['score'] ?? 0,
            'bobot_bs' => $stats['bs']['weight'] ?? 0,
            'nilai_bs_akhir' => ($stats['bs']['score'] ?? 0) * ($stats['bs']['weight'] ?? 0) / 100,
            'bs_total' => $stats['bs']['total'] ?? 0,
            'bs_benar' => $stats['bs']['earned'] ?? 0,

            // Esai
            'nilai_esai_raw' => $stats['esai']['score'] ?? 0,
            'bobot_esai' => $stats['esai']['weight'] ?? 0,
            'nilai_esai_akhir' => ($stats['esai']['score'] ?? 0) * ($stats['esai']['weight'] ?? 0) / 100,
            'esai_total' => $stats['esai']['total'] ?? 0,

            'nilai_total' => $finalScore,
        ]);

        if (ob_get_length()) ob_end_clean();

        // 🔹 Generate PDF
        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();
        $filenameSafe = 'Laporan_Jawaban_' . preg_replace('/[^A-Za-z0-9]/', '_', $student['name'] ?? 'Siswa') . '.pdf';

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filenameSafe . '"')
            ->setBody($output);
    }

    // Helper method to parse options
    private function parseOptions($question)
    {
        $options = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
            $field = 'option_' . strtolower($key);
            if (isset($question[$field]) && !empty($question[$field])) {
                $options[$key] = $question[$field];
            }
        }
        return $options;
    }


    // ✅ Menampilkan modal isi soal esai dan jawaban siswa
    public function getSoalEsai($testId, $studentId)
    {
        $db = db_connect();

        // 🔹 Ambil data bobot esai dari cbt_test_status
        $test = $db->table('cbt_test_status')
            ->select('bank_id, bobot_esai')
            ->where('id', $testId)
            ->get()
            ->getRowArray();

        if (!$test) {
            return '<div class="alert alert-danger">Tes tidak ditemukan.</div>';
        }

        // 🔹 Ambil soal esai dan jawaban siswa
        $soal = $db->table('cbt_questions q')
            ->select('q.id, q.question_text, a.answer, a.score')
            ->join('cbt_answers a', 'a.question_id = q.id AND a.student_id = ' . $studentId, 'left')
            ->where('q.bank_id', $test['bank_id'])
            ->whereIn('q.question_type', ['esai', 'essay'])
            ->orderBy('q.id', 'ASC')
            ->get()
            ->getResultArray();

        if (!$soal) {
            return '<div class="alert alert-warning">Tidak ada soal esai pada tes ini.</div>';
        }

        // 🔹 Kirim data ke view modal
        return view('admin/cbt/aktivitas/modal_nilai_esai', [
            'test_id' => $testId,
            'student_id' => $studentId,
            'bobot_esai' => $test['bobot_esai'] ?? 0,
            'soal' => $soal
        ]);
    }


    // ✅ Simpan nilai esai
    public function simpanNilaiEsaiDetail()
    {
        $testId = $this->request->getPost('test_id');
        $studentId = $this->request->getPost('student_id');
        $nilaiSoal = $this->request->getPost('scores'); // array: [question_id => nilai_per_soal]

        $db = db_connect();

        // 🔹 Ambil bobot dari test
        $test = $db->table('cbt_test_status')
            ->select('bobot_pg, bobot_pg_kompleks, bobot_bs, bobot_esai, bank_id')
            ->where('id', $testId)
            ->get()
            ->getRowArray();

        if (!$test) {
            return $this->response->setJSON(['error' => 'Data ujian tidak ditemukan'])->setStatusCode(404);
        }

        // 🔹 Ambil session untuk mendapatkan question_order
        $session = $db->table('cbt_sessions')
            ->select('question_order')
            ->where('test_id', $testId)
            ->where('student_id', $studentId)
            ->get()
            ->getRowArray();

        if (!$session) {
            return $this->response->setJSON(['error' => 'Sesi tidak ditemukan'])->setStatusCode(404);
        }

        // 🔒 FIX: Get question_order from session
        $questionOrder = json_decode($session['question_order'] ?? '[]', true) ?? [];

        if (empty($questionOrder)) {
            // Fallback to all questions if question_order is empty
            $questions = $db->table('cbt_questions')
                ->select('id, question_type, correct_option')
                ->where('bank_id', $test['bank_id'])
                ->get()
                ->getResultArray();
        } else {
            // 🔒 FIX: Fetch ONLY questions that were shown to student
            $questions = $db->table('cbt_questions')
                ->select('id, question_type, correct_option')
                ->whereIn('id', $questionOrder)
                ->get()
                ->getResultArray();
        }

        // 🔹 Ambil semua jawaban siswa
        $answers = $db->table('cbt_answers')
            ->select('question_id, answer')
            ->where('student_id', $studentId)
            ->where('test_id', $testId)
            ->get()
            ->getResultArray();

        $answerMap = [];
        foreach ($answers as $a) {
            $answerMap[$a['question_id']] = $a['answer'];
        }

        // 🔹 Simpan nilai tiap soal esai di cbt_answers.score
        $totalNilaiEsai = 0;
        $jumlahSoalEsai = 0;

        foreach ($nilaiSoal as $qid => $nilai) {
            $totalNilaiEsai += floatval($nilai);
            $jumlahSoalEsai++;
            $db->table('cbt_answers')
                ->where('question_id', $qid)
                ->where('student_id', $studentId)
                ->update(['score' => $nilai]);
        }

        // 🔹 Recalculate ALL scores using centralized service
        // This ensures weights, essay scaling, and normalization are consistent
        $res = $this->scoringService->getDetailedScoreResults($studentId, $testId);
        
        if (isset($res['error'])) {
            return $this->response->setJSON(['error' => 'Gagal hitung nilai: ' . $res['error']])->setStatusCode(500);
        }

        $finalScore = $res['final_score'];
        $essayScore = $res['stats']['esai']['score'];
        $stats = $res['stats'];
        $weights = $res['weights'];

        // 🔹 Simpan ke cbt_sessions
        $db->table('cbt_sessions')
            ->where('test_id', $testId)
            ->where('student_id', $studentId)
            ->update([
                'score' => $finalScore,
                'essay_score' => $essayScore,
                'total_score' => $finalScore
            ]);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Nilai esai berhasil disimpan',
            'nilai' => [
                'pg' => round($stats['pg']['score'], 2),
                'pgk' => round($stats['pgk']['score'], 2),
                'bs' => round($stats['bs']['score'], 2),
                'esai' => $essayScore,
                'bobot_pg' => $weights['pg'],
                'bobot_pgk' => $weights['pgk'],
                'bobot_bs' => $weights['bs'],
                'bobot_esai' => $weights['esai'],
                'total' => $finalScore,
            ],
        ]);
    }



    /**
     * ✅ Helper to calculate normalized score (0 to 1) for various question types
     */
    private function calculateNormalizedScore($type, $studentAns, $correctAns)
    {
        return $this->scoringService->compareAnswerNormalized($type, $studentAns, $correctAns);
    }

    /**
     * 🎨 Helper method to apply styling and conditional formatting for Analisis Jawaban
     * Evaluates 'too many types' warning in IDE and improves code readability.
     */
    private function applyAnalysisTableStyling(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $currentRow, array $headers)
    {
        $lastCol = $sheet->getHighestColumn();
        $lastRow = $currentRow - 1;

        // Header utama
        $sheet->getStyle("A7:{$lastCol}7")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => '4F81BD']],
        ]);

        // Border seluruh tabel
        $sheet->getStyle("A7:{$lastCol}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'AAAAAA']
                ]
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ========================================
        // ✅ CONDITIONAL FORMATTING HIJAU / MERAH
        // ========================================
        $startColIndex = 7; // Kolom G
        foreach ($headers as $index => $h) {
            $colIndex = $startColIndex + $index;
            $colLetter = Coordinate::stringFromColumnIndex($colIndex);
            $range = "{$colLetter}8:{$colLetter}{$lastRow}";
            $key = $h['key'];

            // Hijau untuk jawaban benar
            $conditionalGreen = new Conditional();
            $conditionalGreen->setConditionType(Conditional::CONDITION_CELLIS);
            $conditionalGreen->setOperatorType(Conditional::OPERATOR_EQUAL);
            $conditionalGreen->addCondition("\"{$key}\"");
            $conditionalGreen->getStyle()->getFill()->setFillType(
                Fill::FILL_SOLID
            )->getStartColor()->setARGB('92D050');

            // Merah untuk jawaban salah
            $conditionalRed = new Conditional();
            $conditionalRed->setConditionType(Conditional::CONDITION_EXPRESSION);
            $conditionalRed->addCondition("AND({$colLetter}8<>\"{$key}\",{$colLetter}8<>\"-\")");
            $conditionalRed->getStyle()->getFill()->setFillType(
                Fill::FILL_SOLID
            )->getStartColor()->setARGB('FF7C80');

            $sheet->getStyle($range)->setConditionalStyles([$conditionalGreen, $conditionalRed]);
        }
    }

    // =========================================================
    // EXPORT NILAI — untuk diimpor ke ESPATA
    // Menghasilkan file JSON berisi nilai siswa dari satu ujian.
    // Kunci pencocokan siswa: NIS (bukan student_id lokal).
    // =========================================================
    public function exportNilai($testId)
    {
        $testId = (int) $testId;
        $db     = $this->db;

        // Info ujian
        $test = $db->table('cbt_test_status ts')
            ->select('ts.id, ts.bank_id, ts.class_codes, ts.semester,
                      qb.code AS bank_code,
                      s.id AS subject_id, s.name AS subject_name, s.code AS subject_code,
                      en.name AS exam_name')
            ->join('cbt_question_banks qb', 'qb.id = ts.bank_id',   'left')
            ->join('subjects s',             's.id = qb.subject_id', 'left')
            ->join('cbt_exam_names en',      'en.id = ts.exam_name_id', 'left')
            ->where('ts.id', $testId)
            ->get()->getRowArray();

        if (!$test) {
            return $this->response->setStatusCode(404)
                ->setJSON(['error' => 'Ujian tidak ditemukan.']);
        }

        // Nilai siswa: ambil sesi terbaru per siswa (MAX id)
        $subquery = "(SELECT student_id, MAX(id) AS max_id
                      FROM cbt_sessions WHERE test_id = {$testId}
                      GROUP BY student_id) latest";

        $rows = $db->table('cbt_sessions cs')
            ->select('st.nis, st.name AS student_name,
                      c.name AS class_name,
                      COALESCE(cs.total_score, cs.score, 0) AS score,
                      cs.status, cs.finished_at')
            ->join('students st', 'st.id = cs.student_id', 'left')
            ->join('classes c',   'c.id = st.class_id',    'left')
            ->join($subquery,     'cs.id = latest.max_id', 'inner')
            ->where('cs.test_id', $testId)
            ->orderBy('c.name', 'ASC')
            ->orderBy('st.name', 'ASC')
            ->get()->getResultArray();

        $students = [];
        foreach ($rows as $r) {
            $students[] = [
                'nis'        => $r['nis'],
                'name'       => $r['student_name'],
                'class_name' => $r['class_name'],
                'score'      => round((float)$r['score'], 2),
                'ikut_ujian' => ($r['status'] === 'finished'),
                'finished_at'=> $r['finished_at'] ?? null,
            ];
        }

        $payload = [
            'format'        => 'espata_cbt_export_v1',
            'exported_at'   => date('Y-m-d H:i:s'),
            'test_id'       => $testId,
            'bank_code'     => $test['bank_code'],
            'exam_name'     => $test['exam_name'],
            'subject_id'    => $test['subject_id'],
            'subject_name'  => $test['subject_name'],
            'subject_code'  => $test['subject_code'],
            'class_codes'   => $test['class_codes'],
            'semester'      => $test['semester'],
            'total_students'=> count($students),
            'students'      => $students,
        ];

        $filename = 'nilai_cbt_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $test['bank_code'])
                    . '_' . date('Ymd_His') . '.json';

        return $this->response
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

