<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

$user = $_SESSION['user_rh'] ?? [];
$userId = (int)($user['id'] ?? 0);

// ===============================
// FLASH MESSAGE
// ===============================
$messages = $_SESSION['flash_messages'] ?? [];
$errors   = $_SESSION['flash_errors']   ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

// ===============================
// VALIDASI PROFIL USER
// ===============================
function isUserProfileComplete(array $u): bool
{
    return !empty($u['batch'])
        && !empty($u['tanggal_masuk'])
        && !empty($u['citizen_id'])
        && !empty($u['jenis_kelamin']);
}

// ===============================
// HANDLE DAFTAR EVENT
// ===============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'daftar_event') {

    $eventId = (int)($_POST['event_id'] ?? 0);

    if (!$eventId || !$userId) {
        $_SESSION['flash_errors'][] = 'Data tidak valid.';
        header('Location: events.php');
        exit;
    }

    if (!isUserProfileComplete($user)) {
        $_SESSION['flash_errors'][] =
            'Mohon lengkapi data Anda terlebih dahulu di Setting Akun, agar bisa mendaftar Event.';
        header('Location: setting_akun.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO event_participants (event_id, user_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$eventId, $userId]);

        $_SESSION['flash_messages'][] = 'Anda berhasil mendaftar event.';
    } catch (PDOException $e) {
        $_SESSION['flash_errors'][] = 'Anda sudah terdaftar di event ini.';
    }

    header('Location: events.php');
    exit;
}

// ===============================
// AMBIL DATA EVENT
// ===============================
$events = $pdo->query("
    SELECT 
        e.*,
        (
            SELECT COUNT(*) 
            FROM event_participants ep 
            WHERE ep.event_id = e.id
        ) AS total_peserta,
        (
            SELECT COUNT(*) 
            FROM event_participants ep 
            WHERE ep.event_id = e.id
            AND ep.user_id = {$userId}
        ) AS is_registered
    FROM events e
    WHERE e.is_active = 1
    ORDER BY e.tanggal_event ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Event';
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page" style="max-width:1100px;margin:auto;">

        <h1 class="gradient-text">Event</h1>
        <p class="text-muted">Daftar dan ikuti event yang tersedia</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header">
                Daftar Event
            </div>

            <div class="table-wrapper">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Event</th>
                            <th>Tanggal</th>
                            <th>Lokasi</th>
                            <th>Peserta</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$events): ?>
                            <tr>
                                <td colspan="6" style="text-align:center;color:#64748b;">
                                    Belum ada event tersedia
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($events as $i => $e): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($e['nama_event']) ?></strong>
                                    <?php if (!empty($e['keterangan'])): ?>
                                        <div style="font-size:12px;color:#64748b;">
                                            <?= htmlspecialchars($e['keterangan']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= (new DateTime($e['tanggal_event']))->format('d M Y') ?>
                                </td>
                                <td><?= htmlspecialchars($e['lokasi'] ?? '-') ?></td>
                                <td><?= (int)$e['total_peserta'] ?> orang</td>
                                <td>
                                    <?php if ($e['is_registered']): ?>
                                        <span class="badge-success">Terdaftar</span>
                                    <?php else: ?>
                                        <button
                                            class="btn-success btn-daftar-event"
                                            data-event-id="<?= (int)$e['id'] ?>"
                                            data-event-name="<?= htmlspecialchars($e['nama_event'], ENT_QUOTES) ?>">
                                            Daftar
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</section>

<!-- ===============================
     MODAL KONFIRMASI DAFTAR EVENT
     =============================== -->
<div id="daftarEventModal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <h3>Konfirmasi Event</h3>

        <p>
            Apakah Anda yakin ingin mengikuti event
            <strong id="eventName"></strong>?
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="daftar_event">
            <input type="hidden" name="event_id" id="eventId">

            <div class="modal-actions">
                <button type="button" class="btn-secondary btn-cancel">Batal</button>
                <button type="submit" class="btn-success">Ya, Daftar</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const modal = document.getElementById('daftarEventModal');
        const eventName = document.getElementById('eventName');
        const eventIdInput = document.getElementById('eventId');

        document.body.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-daftar-event');
            if (!btn) return;

            eventName.innerText = btn.dataset.eventName;
            eventIdInput.value = btn.dataset.eventId;

            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        });

        document.body.addEventListener('click', function(e) {
            if (
                e.target.classList.contains('modal-overlay') ||
                e.target.closest('.btn-cancel')
            ) {
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>