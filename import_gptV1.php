<?php
session_start();
require_once 'config/db.php';

$yearStmt = $conn->query("\n    SELECT DISTINCT exam_year\n    FROM applicantname\n    WHERE exam_year IS NOT NULL\n    ORDER BY exam_year DESC\n");
$years = $yearStmt->fetchAll(PDO::FETCH_COLUMN);

$importMessage = '';
if (isset($_SESSION['import_result'])) {
    $importMessage = (string) $_SESSION['import_result'];
    unset($_SESSION['import_result']);
}

$formatYearLabel = static function ($year): string {
    $raw = trim((string) $year);
    if ($raw === '') {
        return '';
    }

    if (mb_strpos($raw, 'นสต.') === 0) {
        return $raw;
    }

    return 'นสต.' . $raw;
};
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>นำเข้าข้อมูลผู้สมัคร</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --brand: #7a1010;
            --brand-dark: #520707;
            --panel: #ffffff;
            --line: #d9dee8;
            --text: #1f2937;
            --muted: #6b7280;
            --shadow: 0 14px 30px rgba(16, 24, 40, 0.14);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Noto Sans Thai", sans-serif;
            color: var(--text);
            min-height: 100dvh;
            background: radial-gradient(circle at top right, #fbe3e3 0%, #f4f6fb 45%, #eef1f7 100%);
        }

        .top-header {
            background: linear-gradient(110deg, var(--brand-dark), var(--brand));
            color: #fff;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            box-shadow: var(--shadow);
        }

        .brand-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-link {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }

        .logo {
            width: 64px;
            height: 64px;
            object-fit: contain;
        }

        .brand-wrap h1 {
            margin: 0;
            font-size: 1rem;
            line-height: 1.1;
            font-weight: 600;
        }

        .brand-wrap p {
            margin: 2px 0 0;
            opacity: 0.85;
            font-size: 0.72rem;
            line-height: 1.1;
        }

        .header-title {
            font-size: 1.1rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .page {
            width: min(1080px, 100%);
            margin: 20px auto;
            padding: 0 12px 16px;
            display: grid;
            gap: 14px;
        }

        .panel {
            background: rgba(255, 255, 255, 0.84);
            border-radius: 18px;
            box-shadow: var(--shadow);
            border: 1px solid #eceff6;
            backdrop-filter: blur(4px);
        }

        .import-panel {
            padding: 16px;
        }

        .panel-title {
            margin: 0;
            font-size: 1.02rem;
            color: var(--brand);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .panel-subtitle {
            margin: 4px 0 0;
            font-size: 0.82rem;
            color: var(--muted);
        }

        .form-grid {
            margin-top: 12px;
            display: grid;
            grid-template-columns: 200px 1fr auto;
            gap: 10px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .label {
            font-size: 0.82rem;
            color: #374151;
            font-weight: 600;
        }

        .input,
        .file-trigger {
            height: 40px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 0 12px;
            font-size: 0.88rem;
            background: #fff;
        }

        .input:focus {
            outline: 2px solid #fecaca;
            border-color: #fca5a5;
        }

        .file-input {
            display: none;
        }

        .file-trigger {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            background: #fff7f7;
            border-color: #f4b4b4;
            color: #7a1010;
            font-weight: 600;
        }

        .file-trigger:hover {
            background: #ffecec;
        }

        .file-name {
            margin-top: 6px;
            font-size: 0.78rem;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .submit-btn {
            height: 40px;
            border: none;
            border-radius: 999px;
            padding: 0 20px;
            background: linear-gradient(120deg, #b91c1c, #7f1d1d);
            color: #fff;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 16px rgba(127, 29, 29, 0.25);
        }

        .submit-btn:hover {
            filter: brightness(1.03);
        }

        .year-panel {
            padding: 14px 16px;
        }

        .year-grid {
            margin-top: 12px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 8px;
        }

        .year-btn {
            border: 1px solid #d4dbe8;
            background: #fff;
            color: #334155;
            border-radius: 12px;
            padding: 10px 8px;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.15s ease;
        }

        .year-btn:hover {
            border-color: #fca5a5;
            color: #7f1d1d;
            transform: translateY(-1px);
            box-shadow: 0 8px 14px rgba(185, 28, 28, 0.12);
        }

        .empty-year {
            margin-top: 10px;
            color: var(--muted);
            font-size: 0.82rem;
        }

        @media (max-width: 840px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .submit-btn {
                width: 100%;
            }

            .header-title {
                font-size: 0.96rem;
            }
        }

        @media (max-width: 640px) {
            .top-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
                padding: 10px 12px;
            }

            .brand-wrap p {
                display: none;
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
        <div class="header-title">นำเข้าข้อมูลผู้สมัคร</div>
    </header>

    <main class="page">
        <section class="panel import-panel">
            <h2 class="panel-title"><i class="bi bi-database-add"></i>นำเข้าข้อมูลจากไฟล์</h2>
            <p class="panel-subtitle">รองรับไฟล์ CSV และ Excel เพื่อบันทึกข้อมูลผู้สมัครเข้าระบบ</p>
            <?php if ($importMessage !== ''): ?>
                <p class="panel-subtitle" style="color:#0f5132;font-weight:600;"><?= htmlspecialchars($importMessage, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <form action="import_applicant.php" method="post" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="label" for="exam_year">นสต.รุ่นที่</label>
                        <input id="exam_year" class="input" type="number" name="exam_year" placeholder="เช่น 17" required>
                    </div>

                    <div class="form-group">
                        <label class="label" for="file">ไฟล์ข้อมูล</label>
                        <input id="file" class="file-input" type="file" name="file" accept=".csv,.xlsx" required>
                        <label for="file" class="file-trigger">
                            <i class="bi bi-file-earmark-arrow-up"></i>
                            เลือกไฟล์ Excel / CSV
                        </label>
                        <div id="fileName" class="file-name">ยังไม่ได้เลือกไฟล์</div>
                    </div>

                    <button type="submit" class="submit-btn">บันทึกข้อมูล</button>
                </div>
            </form>
        </section>

        <section class="panel year-panel">
            <h2 class="panel-title"><i class="bi bi-calendar3"></i>เลือกปีที่มีข้อมูล</h2>
            <p class="panel-subtitle">กดปีเพื่อเข้าสู่หน้าจัดการข้อมูลของปีนั้นทันที</p>

            <?php if (count($years) > 0): ?>
                <div class="year-grid">
                    <?php foreach ($years as $year): ?>
                        <button type="button" class="year-btn" onclick="goToYear('<?= htmlspecialchars((string) $year, ENT_QUOTES, 'UTF-8') ?>')">
                            <?= htmlspecialchars($formatYearLabel($year), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-year">ยังไม่มีข้อมูลปีสอบในระบบ</p>
            <?php endif; ?>
        </section>
    </main>

    <script>
        const fileInput = document.getElementById('file');
        const fileName = document.getElementById('fileName');

        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileName.textContent = this.files[0].name;
            } else {
                fileName.textContent = 'ยังไม่ได้เลือกไฟล์';
            }
        });

        function goToYear(year) {
            window.location.href = 'menu.php?exam_year=' + encodeURIComponent(year);
        }
    </script>
</body>

</html>
