<?= $this->extend('layouts/cbt') ?>
<?= $this->section('content') ?>

<style>
  .rules-container {
    max-width: 680px;
    margin: 1rem auto 2.5rem;
    padding: 0 12px;
  }
  .rules-card {
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
    overflow: hidden;
  }
  .rules-header {
    background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
    color: #ffffff;
    padding: 24px 20px;
    text-align: center;
    position: relative;
  }
  .rules-header .icon-wrap {
    width: 60px;
    height: 60px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    margin-bottom: 10px;
  }
  .exam-summary-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 20px;
  }
  .rules-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
  }
  .rule-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
  }
  .rule-item:last-child {
    border-bottom: none;
  }
  .rule-icon {
    width: 32px;
    height: 32px;
    border-radius: 10px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 0.95rem;
    margin-top: 2px;
  }
  .btn-start-exam-main {
    border-radius: 50px;
    padding: 14px 20px;
    font-size: 1.05rem;
    font-weight: 700;
    letter-spacing: 0.3px;
    box-shadow: 0 4px 14px rgba(13, 110, 253, 0.35);
    transition: all 0.2s ease;
  }
  .btn-start-exam-main:active {
    transform: scale(0.98);
  }

  @media (max-width: 576px) {
    .rules-container {
      margin-top: 0.5rem;
      padding: 0 8px;
    }
    .rules-header {
      padding: 20px 15px;
    }
    .rules-header h3 {
      font-size: 1.25rem !important;
    }
    .rules-header .icon-wrap {
      width: 50px;
      height: 50px;
      font-size: 1.5rem;
      margin-bottom: 8px;
    }
    .rule-item {
      font-size: 0.9rem;
    }
    .btn-start-exam-main {
      padding: 12px 18px;
      font-size: 1rem;
    }
  }
</style>

<div class="rules-container">
  <!-- ⚠️ PERINGATAN BROWSER TIDAK SESUAI / IN-APP BROWSER (WHATSAPP, TELEGRAM, IG, LINE) -->
  <div id="inAppBrowserAlert" class="alert alert-warning border-warning d-none mb-3 shadow-sm p-3" style="border-radius: 16px;">
    <div class="d-flex align-items-start gap-2">
      <i class="bi bi-exclamation-triangle-fill text-warning fs-3 flex-shrink-0 mt-1"></i>
      <div class="flex-grow-1">
        <h6 class="fw-bold text-dark mb-1">Perhatian: Browser Aplikasi Terdeteksi!</h6>
        <p class="small text-secondary mb-2" id="inAppDesc">
          Anda membuka ujian dari browser bawaan WhatsApp/Sosial Media. Sesi ujian dapat terputus atau tertutup otomatis jika layar HP mati.
        </p>
        <div class="d-flex flex-wrap gap-2 pt-1" id="inAppActions">
          <a href="#" id="btnOpenChrome" class="btn btn-sm btn-primary rounded-pill px-3 d-none">
            <i class="bi bi-browser-chrome me-1"></i> Buka di Google Chrome
          </a>
          <button type="button" id="btnCopyExamLink" class="btn btn-sm btn-outline-dark rounded-pill px-3">
            <i class="bi bi-clipboard me-1"></i> Salin Link Ujian
          </button>
        </div>
        <div id="inAppGuide" class="small text-muted mt-2 d-none">
          <i class="bi bi-info-circle me-1"></i> Ketuk titik tiga (⋮) di pojok kanan atas, lalu pilih <b>"Buka di Browser Luar / Buka di Chrome / Safari"</b>.
        </div>
      </div>
    </div>
  </div>

  <div class="rules-card">
    <div class="rules-header">
      <div class="icon-wrap">
        <i class="bi bi-shield-check"></i>
      </div>
      <h3 class="fw-bold mb-1 fs-4">Petunjuk & Peraturan Ujian</h3>
      <p class="mb-0 opacity-90 small">Harap baca dengan cermat sebelum memulai ujian</p>
    </div>

    <div class="p-3 p-md-4">
      <div class="exam-summary-box">
        <div class="fw-bold text-primary mb-1 fs-5"><?= esc($test['subject_name'] ?? 'Mata Pelajaran') ?></div>
        <div class="small text-muted mb-2"><?= esc($test['exam_name'] ?? 'Ujian') ?> &bull; Kode: <?= esc($test['bank_code'] ?? '-') ?></div>
        <div class="d-flex flex-wrap align-items-center gap-2 pt-2 border-top">
          <span class="badge bg-primary text-white rounded-pill px-3 py-2">
            <i class="bi bi-clock me-1"></i> Durasi: <?= esc($test['duration'] ?? 0) ?> menit
          </span>
          <span class="badge bg-light text-secondary border rounded-pill px-3 py-2">
            <i class="bi bi-journal-text me-1"></i> Mode CBT Online
          </span>
        </div>
      </div>

      <div class="rules-list">
        <div class="rule-item">
          <div class="rule-icon"><i class="bi bi-heart-fill text-danger"></i></div>
          <div><b>Berdoa sebelum mulai</b> untuk ketenangan dan kelancaran pengerjaan.</div>
        </div>
        <div class="rule-item">
          <div class="rule-icon"><i class="bi bi-fullscreen text-primary"></i></div>
          <div>Ujian berjalan dalam <b>Layar Penuh (Full Screen)</b>. Dilarang berpindah aplikasi, tab, membuka notifikasi, atau mengaktifkan mode <b>Layar Belah (Split Screen)</b>.</div>
        </div>
        <div class="rule-item">
          <div class="rule-icon"><i class="bi bi-wifi text-success"></i></div>
          <div>Pastikan koneksi internet aktif. Seluruh jawaban <b>disimpan otomatis</b> ke server secara berkala.</div>
        </div>
        <div class="rule-item">
          <div class="rule-icon"><i class="bi bi-hourglass-split text-warning"></i></div>
          <div>Waktu ujian akan langsung berkurang begitu tombol <b>Mulai Ujian</b> ditekan.</div>
        </div>
        <div class="rule-item">
          <div class="rule-icon"><i class="bi bi-patch-check-fill text-info"></i></div>
          <div>Prestasi penting, namun <b>kejujuran adalah yang utama</b>!</div>
        </div>
      </div>

      <div class="pt-2">
        <a href="<?= site_url('siswa/cbt/mulai/' . $test['id']) ?>" class="btn btn-primary btn-lg w-100 btn-start-exam-main" id="btnMulai">
          <i class="bi bi-play-circle-fill me-2"></i> Mulai Ujian Sekarang
        </a>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
  // 🔍 DETEKSI IN-APP BROWSER (WhatsApp, Telegram, IG, FB, Line) & OPERA MINI
  (function checkClientBrowser() {
    const ua = navigator.userAgent || navigator.vendor || window.opera || '';
    const isInApp = /FBAN|FBAV|Instagram|Line|WhatsApp|Telegram|MicroMessenger|musical_ly|BytedanceWebview/i.test(ua)
      || (/(iPhone|iPod|iPad).*AppleWebKit(?!.*Safari)/i.test(ua));
    const isOperaMini = (ua.indexOf('Opera Mini') > -1) || ('operamini' in window);
    const isAndroid = /Android/i.test(ua);

    const alertBox = document.getElementById('inAppBrowserAlert');
    const desc = document.getElementById('inAppDesc');
    const btnChrome = document.getElementById('btnOpenChrome');
    const btnCopy = document.getElementById('btnCopyExamLink');
    const guide = document.getElementById('inAppGuide');

    if (isOperaMini) {
      if (alertBox) {
        alertBox.classList.remove('d-none');
        if (desc) {
          desc.textContent = 'Browser Opera Mini dengan mode kompresi data ekstrem tidak didukung karena menghambat penyimpanan jawaban ujian. Mohon gunakan Google Chrome atau browser standar HP Anda.';
        }
      }
    } else if (isInApp) {
      if (alertBox) {
        alertBox.classList.remove('d-none');
        if (guide) guide.classList.remove('d-none');

        if (isAndroid && btnChrome) {
          btnChrome.classList.remove('d-none');
          // Intent link scheme untuk membuka Google Chrome asli di Android
          const currentUrlNoScheme = window.location.href.replace(/^https?:\/\//i, '');
          const isHttps = window.location.protocol === 'https:';
          btnChrome.href = `intent://${currentUrlNoScheme}#Intent;scheme=${isHttps ? 'https' : 'http'};package=com.android.chrome;end`;
        }

        if (btnCopy) {
          btnCopy.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(window.location.href).then(() => {
                Swal.fire({
                  icon: 'success',
                  title: 'Link Berhasil Disalin!',
                  text: 'Silakan buka aplikasi Google Chrome atau Safari di HP Anda lalu tempel link ini.',
                  timer: 3500,
                  showConfirmButton: false
                });
              });
            } else {
              prompt('Salin link ujian berikut lalu buka di Chrome/Safari:', window.location.href);
            }
          });
        }
      }
    }
  })();

  let isSubmitting = false;

  // Reset state if returned via back/forward cache
  window.addEventListener('pageshow', function (event) {
    if (event.persisted || performance.navigation.type === 2) {
      isSubmitting = false;
      const btn = document.getElementById('btnMulai');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle-fill me-2"></i> Mulai Ujian Sekarang';
        btn.classList.remove('disabled');
      }
    }
  });

  document.getElementById('btnMulai').addEventListener('click', function (e) {
    e.preventDefault();
    if (isSubmitting) return;

    // 🛡️ ANTI-CHEAT: Pengecekan Layar Belah (Split Screen) sebelum mulai ujian
    if (window.innerWidth <= 992) {
      const isPortrait = window.innerHeight > window.innerWidth;
      const heightRatio = window.innerHeight / window.screen.height;
      if (isPortrait && heightRatio < 0.65) {
        Swal.fire({
          title: 'Layar Belah Terdeteksi!',
          html: `
            <div class="text-start">
              <p class="mb-2">Anda terdeteksi menggunakan mode <b>Layar Belah (Split Screen)</b> atau jendela melayang.</p>
              <div class="alert alert-warning small mb-0">
                Ujian mewajibkan <b>Layar Penuh satu layar utuh</b>. Harap tutup aplikasi lain terlebih dahulu untuk memulai ujian.
              </div>
            </div>
          `,
          icon: 'warning',
          confirmButtonText: 'Tutup Aplikasi Lain & Coba Lagi',
          confirmButtonColor: '#0d6efd'
        });
        return;
      }
    }

    isSubmitting = true;
    const btn = this;
    const targetUrl = btn.href;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menyiapkan Lembar Soal...';
    btn.classList.add('disabled');

    // 🔹 OPTIMASI SHARED HOSTING: Jitter acak 300ms - 2200ms
    // untuk memecah lonjakan 'Start Rush' saat 100+ siswa klik Mulai Ujian secara serentak
    const startJitter = 300 + Math.floor(Math.random() * 1900);

    const proceedToExam = () => {
      setTimeout(function () {
        window.location.href = targetUrl;
      }, startJitter);
    };

    // Detect iOS (iPhone / iPad / iPod) - Fullscreen API is unsupported on mobile Safari
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    if (isIOS) {
      proceedToExam();
      return;
    }

    const elem = document.documentElement;
    const req = elem.requestFullscreen || elem.webkitRequestFullscreen || elem.msRequestFullscreen || elem.mozRequestFullScreen;

    if (req) {
      req.call(elem).then(function () {
        proceedToExam();
      }).catch(function (err) {
        console.warn('[CBT] Fullscreen permission skipped:', err);
        proceedToExam();
      });
    } else {
      proceedToExam();
    }
  });
</script>
<?= $this->endSection() ?>