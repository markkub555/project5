<?php
session_start();
require_once 'config/db.php';
require_once __DIR__ . '/includes/user_profile.php';

if (!isset($_SESSION['user_login'])) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['exam_year']) && $_GET['exam_year'] !== '') {
    $_SESSION['exam_year'] = $_GET['exam_year'];
}

if (!isset($_SESSION['exam_year'])) {
    header('Location: import_gptV1.php');
    exit;
}

$examYear = (string) $_SESSION['exam_year'];
$userProfile = getCurrentUserProfile($conn);
$pageUpdatedAt = date('Y-m-d H:i', filemtime(__FILE__));
$assetVersion = (string) filemtime(__FILE__);

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};

$sql = "
    SELECT
        SUM(CASE WHEN submit_doc = 'P' THEN 1 ELSE 0 END) AS doc_pass,
        SUM(CASE WHEN submit_doc = 'F' THEN 1 ELSE 0 END) AS doc_fail,
        SUM(CASE WHEN lab_check = 'P' THEN 1 ELSE 0 END) AS lab_pass,
        SUM(CASE WHEN lab_check = 'F' THEN 1 ELSE 0 END) AS lab_fail,
        SUM(CASE WHEN swim_test = 'P' THEN 1 ELSE 0 END) AS swim_pass,
        SUM(CASE WHEN swim_test = 'F' THEN 1 ELSE 0 END) AS swim_fail,
        SUM(CASE WHEN run_test = 'P' THEN 1 ELSE 0 END) AS run_pass,
        SUM(CASE WHEN run_test = 'F' THEN 1 ELSE 0 END) AS run_fail,
        SUM(CASE WHEN station3_test = 'P' THEN 1 ELSE 0 END) AS station3_pass,
        SUM(CASE WHEN station3_test = 'F' THEN 1 ELSE 0 END) AS station3_fail,
        SUM(CASE WHEN hospital_check = 'P' THEN 1 ELSE 0 END) AS hospital_pass,
        SUM(CASE WHEN hospital_check = 'F' THEN 1 ELSE 0 END) AS hospital_fail,
        SUM(CASE WHEN fingerprint_check = 'P' THEN 1 ELSE 0 END) AS fingerprint_pass,
        SUM(CASE WHEN fingerprint_check = 'F' THEN 1 ELSE 0 END) AS fingerprint_fail,
        SUM(CASE WHEN background_check = 'P' THEN 1 ELSE 0 END) AS background_pass,
        SUM(CASE WHEN background_check = 'F' THEN 1 ELSE 0 END) AS background_fail,
        SUM(CASE WHEN interview = 'P' THEN 1 ELSE 0 END) AS interview_pass,
        SUM(CASE WHEN interview = 'F' THEN 1 ELSE 0 END) AS interview_fail,
        SUM(CASE WHEN militarydoc = 'P' THEN 1 ELSE 0 END) AS military_pass,
        SUM(CASE WHEN militarydoc = 'F' THEN 1 ELSE 0 END) AS military_fail
    FROM applicantname
    WHERE id <> 'id' AND exam_year = :exam_year
";

$stmt = $conn->prepare($sql);
$stmt->execute([':exam_year' => $examYear]);
$data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalStmt = $conn->prepare("SELECT COUNT(*) FROM applicantname WHERE id <> 'id' AND exam_year = :exam_year");
$totalStmt->execute([':exam_year' => $examYear]);
$totalApplicants = (int) $totalStmt->fetchColumn();

$chartData = [
    'doc_pass' => (int) ($data['doc_pass'] ?? 0),
    'doc_fail' => (int) ($data['doc_fail'] ?? 0),
    'lab_pass' => (int) ($data['lab_pass'] ?? 0),
    'lab_fail' => (int) ($data['lab_fail'] ?? 0),
    'swim_pass' => (int) ($data['swim_pass'] ?? 0),
    'swim_fail' => (int) ($data['swim_fail'] ?? 0),
    'run_pass' => (int) ($data['run_pass'] ?? 0),
    'run_fail' => (int) ($data['run_fail'] ?? 0),
    'station3_pass' => (int) ($data['station3_pass'] ?? 0),
    'station3_fail' => (int) ($data['station3_fail'] ?? 0),
    'hospital_pass' => (int) ($data['hospital_pass'] ?? 0),
    'hospital_fail' => (int) ($data['hospital_fail'] ?? 0),
    'fingerprint_pass' => (int) ($data['fingerprint_pass'] ?? 0),
    'fingerprint_fail' => (int) ($data['fingerprint_fail'] ?? 0),
    'background_pass' => (int) ($data['background_pass'] ?? 0),
    'background_fail' => (int) ($data['background_fail'] ?? 0),
    'interview_pass' => (int) ($data['interview_pass'] ?? 0),
    'interview_fail' => (int) ($data['interview_fail'] ?? 0),
    'military_pass' => (int) ($data['military_pass'] ?? 0),
    'military_fail' => (int) ($data['military_fail'] ?? 0),
];

$chartLabels = [
    'ยื่นเอกสาร',
    'LAB',
    'ว่ายน้ำ',
    'วิ่ง',
    '๓ สถานี',
    'รพ.ตร.',
    'ลายนิ้วมือ',
    'ประวัติทางคดี',
    'สัมภาษณ์',
    'เอกสารทางทหาร',
];

$chartPassData = [
    $chartData['doc_pass'],
    $chartData['lab_pass'],
    $chartData['swim_pass'],
    $chartData['run_pass'],
    $chartData['station3_pass'],
    $chartData['hospital_pass'],
    $chartData['fingerprint_pass'],
    $chartData['background_pass'],
    $chartData['interview_pass'],
    $chartData['military_pass'],
];

$chartFailData = [
    $chartData['doc_fail'],
    $chartData['lab_fail'],
    $chartData['swim_fail'],
    $chartData['run_fail'],
    $chartData['station3_fail'],
    $chartData['hospital_fail'],
    $chartData['fingerprint_fail'],
    $chartData['background_fail'],
    $chartData['interview_fail'],
    $chartData['military_fail'],
];

$stageSummary = [
    ['label' => 'ยื่นเอกสาร', 'pass' => $chartData['doc_pass'], 'fail' => $chartData['doc_fail']],
    ['label' => 'ตรวจ LAB', 'pass' => $chartData['lab_pass'], 'fail' => $chartData['lab_fail']],
    ['label' => 'ว่ายน้ำ', 'pass' => $chartData['swim_pass'], 'fail' => $chartData['swim_fail']],
    ['label' => 'วิ่ง', 'pass' => $chartData['run_pass'], 'fail' => $chartData['run_fail']],
    ['label' => '๓ สถานี', 'pass' => $chartData['station3_pass'], 'fail' => $chartData['station3_fail']],
    ['label' => 'ตรวจร่างกาย รพ.ตร.', 'pass' => $chartData['hospital_pass'], 'fail' => $chartData['hospital_fail']],
    ['label' => 'ตรวจลายนิ้วมือ ศพฐ.', 'pass' => $chartData['fingerprint_pass'], 'fail' => $chartData['fingerprint_fail']],
    ['label' => 'ตรวจประวัติทางคดี', 'pass' => $chartData['background_pass'], 'fail' => $chartData['background_fail']],
    ['label' => 'สัมภาษณ์', 'pass' => $chartData['interview_pass'], 'fail' => $chartData['interview_fail']],
    ['label' => 'เอกสารทางทหาร', 'pass' => $chartData['military_pass'], 'fail' => $chartData['military_fail']],
];

foreach ($stageSummary as &$stage) {
    $total = $stage['pass'] + $stage['fail'];
    $stage['total'] = $total;
    $stage['fail_rate'] = $total > 0 ? round(($stage['fail'] / $total) * 100, 1) : 0;
}
unset($stage);

usort($stageSummary, static function (array $a, array $b): int {
    if ($a['fail'] === $b['fail']) {
        return $b['fail_rate'] <=> $a['fail_rate'];
    }
    return $b['fail'] <=> $a['fail'];
});
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สรุปข้อมูลการสอบ นสต.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/all-name.css?v=<?= $h($assetVersion) ?>" rel="stylesheet">
    <style>
        .content {
            overflow: auto;
        }

        .hero {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            padding: 16px;
            border-radius: 18px;
            background: linear-gradient(135deg, #fff 0%, #fff5f5 45%, #f7f8ff 100%);
            border: 1px solid var(--line);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
            margin-bottom: 14px;
        }

        .hero-title {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .hero-subtitle {
            color: var(--muted);
            font-size: 0.92rem;
            margin: 0;
        }

        .kpi-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            min-width: 260px;
        }

        .kpi {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .kpi-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: #fff1f2;
            color: #9f1239;
        }

        .kpi-label {
            font-size: 0.78rem;
            color: var(--muted);
            margin: 0;
        }

        .kpi-value {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
        }

        .panel-grid {
            display: grid;
            grid-template-columns: minmax(0, 2.1fr) minmax(0, 1fr);
            gap: 14px;
        }

        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .legend-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            font-size: 0.84rem;
            color: var(--muted);
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
        }

        .chart-wrap {
            position: relative;
            height: min(420px, 55vh);
        }

        .chart-note {
            margin-top: 10px;
            color: var(--muted);
            font-size: 0.85rem;
        }

        .insight-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px;
            height: 100%;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .insight-title {
            font-size: 0.95rem;
            font-weight: 600;
            margin: 0;
        }

        .insight-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 10px;
            background: #f8fafc;
        }

        .insight-label {
            font-size: 0.85rem;
            font-weight: 600;
            margin: 0;
        }

        .insight-meta {
            font-size: 0.78rem;
            color: var(--muted);
            margin: 0;
        }

        .insight-badge {
            min-width: 62px;
            text-align: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: #fee2e2;
            color: #991b1b;
            font-weight: 700;
            font-size: 0.82rem;
        }

        @media (max-width: 900px) {
            .kpi-row {
                grid-template-columns: 1fr;
                width: 100%;
            }

            .panel-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <header class="top-header">
        <div class="brand-wrap">
            <a href="menu.php" class="logo-link" aria-label="กลับหน้าเมนูหลัก">
                <img src="upload/tcpr5-1024x990.png" class="logo" alt="ตราศูนย์ฝึกอบรมตำรวจภูธรภาค 5">
            </a>
            <div>
                <h1>ศูนย์ฝึกอบรมตำรวจภูธรภาค ๕</h1>
                <p>TRAINING CENTER OF PROVINCIAL POLICE REGION 5</p>
            </div>
        </div>
        <div class="header-right">
            <a class="header-home" href="menu.php" aria-label="กลับหน้าเมนูหลัก" style="color:#fff;">
                <i class="bi bi-house-door-fill" style="color:#fff;"></i>
            </a>
            <div class="header-meta">
                <strong>สรุปข้อมูลการสอบ นสต.</strong>
                <span>ปีที่ใช้งาน: <?= $h($examYear) ?></span>
                <span style="font-size:0.72rem; opacity:0.85;">อัปเดตล่าสุด: <?= $h($pageUpdatedAt) ?></span>
            </div>
            <div class="profile-menu">
                <button id="profileTrigger" type="button" class="profile-trigger">
                    <i class="bi bi-person-circle"></i>
                    <span><?= $h($userProfile['firstname']) ?></span>
                    <i class="bi bi-caret-down-fill"></i>
                </button>
                <div id="profileCard" class="profile-card">
                    <p class="profile-name"><?= $h($userProfile['fullname']) ?></p>
                    <?php if ($userProfile['username'] !== ''): ?>
                        <p class="profile-username">@<?= $h($userProfile['username']) ?></p>
                    <?php endif; ?>
                    <a class="logout-btn" href="logout.php">
                        <i class="bi bi-box-arrow-right"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar">
            <div class="menu-title">เมนู</div>
            <a class="menu-btn" href="All name.php">รายชื่อทั้งหมด</a>
            <a class="menu-btn" href="document.php">ยื่นเอกสาร</a>
            <a class="menu-btn" href="lab_check.php">ตรวจ LAB</a>
            <a class="menu-btn" href="swim.php">ว่ายน้ำ</a>
            <a class="menu-btn" href="run.php">วิ่ง</a>
            <a class="menu-btn" href="3station.php">๓ สถานี</a>
            <a class="menu-btn" href="hospital_check.php">ตรวจร่างกาย รพ.ตร.</a>
            <a class="menu-btn" href="fingerprint_check.php">ตรวจลายนิ้วมือ ศพฐ.</a>
            <a class="menu-btn" href="background_check.php">ตรวจประวัติทางคดี</a>
            <a class="menu-btn" href="interview.php">สัมภาษณ์</a>
            <a class="menu-btn" href="militarydoc.php">เอกสารทางทหาร</a>
            <a class="menu-btn" href="Step.php">สรุปผลรายขั้นตอน</a>
            <a class="menu-btn" href="selected.php">ผู้ได้รับการคัดเลือก</a>
            <a class="menu-btn active" href="final.php">สรุปข้อมูลการสอบ นสต.</a>
        </aside>

        <main class="content">
            <section class="hero">
                <div>
                    <h2 class="hero-title">สรุปข้อมูลการสอบ นสต.</h2>
                    <p class="hero-subtitle">ภาพรวมผลการทดสอบรายขั้นตอน ประจำปี <?= $h($examYear) ?></p>
                </div>
                <div class="kpi-row">
                    <div class="kpi">
                        <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                        <div>
                            <p class="kpi-label">ผู้สมัครทั้งหมด</p>
                            <p class="kpi-value"><?= number_format($totalApplicants) ?> คน</p>
                        </div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-icon" style="background:#dcfce7;color:#166534;"><i class="bi bi-clipboard2-check-fill"></i></div>
                        <div>
                            <p class="kpi-label">จำนวนขั้นตอน</p>
                            <p class="kpi-value">10 ขั้นตอน</p>
                        </div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-icon" style="background:#e0f2fe;color:#0c4a6e;"><i class="bi bi-graph-up"></i></div>
                        <div>
                            <p class="kpi-label">กราฟแยกผล</p>
                            <p class="kpi-value">ผ่าน / ไม่ผ่าน</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-grid">
                    <div>
                        <div class="panel-head">
                            <div class="panel-title">สรุปผลการทดสอบ 10 ขั้นตอน</div>
                            <div class="legend-row">
                                <div class="legend-item"><span class="legend-dot" style="background:#22c55e;"></span>ผ่าน</div>
                                <div class="legend-item"><span class="legend-dot" style="background:#ef4444;"></span>ไม่ผ่าน</div>
                            </div>
                        </div>
                        <div class="chart-wrap">
                            <canvas id="examChart"></canvas>
                        </div>
                        <div class="chart-note">หมายเหตุ: แผนภูมิแสดงจำนวนผู้ผ่านและไม่ผ่านแยกตามขั้นตอน ไม่ใช่ผลรวมทั้งกระบวนการ</div>
                    </div>
                    <div class="insight-card">
                        <p class="insight-title">ขั้นตอนที่มีผู้ไม่ผ่านสูงสุด</p>
                        <?php foreach (array_slice($stageSummary, 0, 3) as $stage): ?>
                            <div class="insight-item">
                                <div>
                                    <p class="insight-label"><?= $h($stage['label']) ?></p>
                                    <p class="insight-meta">ไม่ผ่าน <?= number_format($stage['fail']) ?> คน (<?= number_format($stage['fail_rate'], 1) ?>%)</p>
                                </div>
                                <div class="insight-badge"><?= number_format($stage['fail']) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$stageSummary): ?>
                            <div class="insight-item">
                                <div>
                                    <p class="insight-label">ยังไม่มีข้อมูล</p>
                                    <p class="insight-meta">กรุณาเพิ่มข้อมูลผู้สมัครก่อน</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('examChart');
            if (!canvas) {
                return;
            }

            if (typeof Chart === 'undefined') {
                canvas.parentElement.innerHTML = '<div class="text-danger">โหลดกราฟไม่สำเร็จ (Chart.js ไม่พร้อมใช้งาน)</div>';
                return;
            }

            const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const passData = <?= json_encode($chartPassData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const failData = <?= json_encode($chartFailData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                            label: 'ผ่าน',
                            data: passData,
                            backgroundColor: '#22c55e',
                            borderRadius: 6,
                            borderSkipped: false
                        },
                        {
                            label: 'ไม่ผ่าน',
                            data: failData,
                            backgroundColor: '#ef4444',
                            borderRadius: 6,
                            borderSkipped: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: false,
                            text: 'สรุปผลการทดสอบ 10 ขั้นตอน'
                        },
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            titleColor: '#fff',
                            bodyColor: '#fff'
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 0,
                                minRotation: 0,
                                autoSkip: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.25)'
                            },
                            ticks: {
                                stepSize: 5
                            }
                        }
                    },
                    categoryPercentage: 0.7,
                    barPercentage: 0.8
                }
            });
        });

        const profileTrigger = document.getElementById('profileTrigger');
        const profileCard = document.getElementById('profileCard');

        profileTrigger.addEventListener('click', function(event) {
            event.stopPropagation();
            profileCard.classList.toggle('open');
        });

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.profile-menu')) {
                profileCard.classList.remove('open');
            }
        });
    </script>
</body>

</html>
