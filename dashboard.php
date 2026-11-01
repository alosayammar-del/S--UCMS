<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'مستخدم النظام';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? '';

$roles = [
    'student' => 'طالب',
    'faculty' => 'عضو هيئة تدريس',
    'staff' => 'موظف',
    'administrator' => 'مسؤول النظام'
];

if (!isset($roles[$role])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

$roleName = $roles[$role];

$stmt = $pdo->prepare(
    "SELECT id, full_name, email, role, status
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || $user['status'] !== 'active' || $user['role'] !== $role) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

$fullName = $user['full_name'];
$email = $user['email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('طلب غير صالح.');
    }

    session_unset();
    session_destroy();

    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stats = [
    'students' => 0,
    'faculty' => 0,
    'staff' => 0,
    'courses' => 0,
    'classrooms' => 0,
    'requests' => 0,
    'assignments' => 0,
    'attendance' => 0,
    'notifications' => 0,
    'alerts' => 0
];

if ($role === 'administrator') {
    $queries = [
        'students' => "SELECT COUNT(*) FROM students",
        'faculty' => "SELECT COUNT(*) FROM faculty_members",
        'staff' => "SELECT COUNT(*) FROM users WHERE role = 'staff'",
        'courses' => "SELECT COUNT(*) FROM courses",
        'classrooms' => "SELECT COUNT(*) FROM classrooms",
        'requests' => "SELECT COUNT(*) FROM university_requests",
        'assignments' => "SELECT COUNT(*) FROM assignments",
        'attendance' => "SELECT COUNT(*) FROM attendance",
        'notifications' => "SELECT COUNT(*) FROM notifications",
        'alerts' => "SELECT COUNT(*) FROM academic_alerts"
    ];

    foreach ($queries as $key => $query) {
        try {
            $stats[$key] = (int) $pdo->query($query)->fetchColumn();
        } catch (PDOException $e) {
            error_log($e->getMessage());
        }
    }
}

$dashboardContent = [
    'student' => [
        'title' => 'بوابة الطالب',
        'description' => 'تابع مسيرتك الأكاديمية وخدماتك الجامعية من مكان واحد.',
        'items' => [
            ['icon' => '▦', 'title' => 'المقررات الدراسية', 'description' => 'متابعة المقررات المسجل بها'],
            ['icon' => '◷', 'title' => 'الجدول الدراسي', 'description' => 'عرض مواعيد المحاضرات'],
            ['icon' => '▤', 'title' => 'الدرجات والتقييمات', 'description' => 'متابعة نتائجك الأكاديمية'],
            ['icon' => '✓', 'title' => 'الحضور والغياب', 'description' => 'متابعة سجل الحضور'],
            ['icon' => '✉', 'title' => 'الطلبات الجامعية', 'description' => 'متابعة طلباتك الجامعية'],
            ['icon' => '♧', 'title' => 'التنبيهات الأكاديمية', 'description' => 'عرض التنبيهات والإشعارات']
        ]
    ],
    'faculty' => [
        'title' => 'بوابة عضو هيئة التدريس',
        'description' => 'إدارة المقررات والأنشطة الأكاديمية ومتابعة أداء الطلاب.',
        'items' => [
            ['icon' => '▦', 'title' => 'المقررات الدراسية', 'description' => 'متابعة المقررات المسندة إليك'],
            ['icon' => '◷', 'title' => 'الجداول الدراسية', 'description' => 'عرض جداول المحاضرات'],
            ['icon' => '✓', 'title' => 'إدارة الحضور', 'description' => 'متابعة حضور الطلاب'],
            ['icon' => '▤', 'title' => 'الدرجات والتقييمات', 'description' => 'إدارة درجات الطلاب'],
            ['icon' => '✎', 'title' => 'الواجبات والتكليفات', 'description' => 'إدارة الواجبات الأكاديمية'],
            ['icon' => '♧', 'title' => 'الإعلانات والتنبيهات', 'description' => 'متابعة الإعلانات الأكاديمية']
        ]
    ],
    'staff' => [
        'title' => 'بوابة الموظف',
        'description' => 'الوصول إلى الخدمات والمهام الإدارية الجامعية.',
        'items' => [
            ['icon' => '▤', 'title' => 'الطلبات الجامعية', 'description' => 'متابعة الطلبات والمعاملات'],
            ['icon' => '✉', 'title' => 'الإشعارات', 'description' => 'عرض الإشعارات الواردة'],
            ['icon' => '▦', 'title' => 'الخدمات الجامعية', 'description' => 'متابعة الخدمات المتاحة'],
            ['icon' => '◷', 'title' => 'المهام الإدارية', 'description' => 'متابعة الأعمال الإدارية']
        ]
    ],
    'administrator' => [
        'title' => 'لوحة إدارة النظام',
        'description' => 'إدارة ومتابعة مختلف العمليات الأكاديمية والإدارية.',
        'items' => [
            ['icon' => '♙', 'title' => 'إدارة المستخدمين', 'description' => 'إدارة حسابات وصلاحيات المستخدمين'],
            ['icon' => '▦', 'title' => 'إدارة الطلاب', 'description' => 'متابعة بيانات الطلاب'],
            ['icon' => '♧', 'title' => 'إدارة أعضاء هيئة التدريس', 'description' => 'متابعة بيانات أعضاء هيئة التدريس'],
            ['icon' => '▤', 'title' => 'إدارة المقررات', 'description' => 'إدارة المقررات والتسجيل'],
            ['icon' => '◷', 'title' => 'إدارة الجداول', 'description' => 'متابعة الجداول والقاعات'],
            ['icon' => '✓', 'title' => 'الحضور والدرجات', 'description' => 'متابعة السجلات الأكاديمية'],
            ['icon' => '✉', 'title' => 'الطلبات الجامعية', 'description' => 'متابعة الطلبات والمعاملات'],
            ['icon' => '▥', 'title' => 'التقارير والإحصائيات', 'description' => 'عرض مؤشرات النظام']
        ]
    ]
];

$content = $dashboardContent[$role];

$adminStats = [
    ['label' => 'الطلاب', 'value' => $stats['students'], 'icon' => '♙'],
    ['label' => 'أعضاء هيئة التدريس', 'value' => $stats['faculty'], 'icon' => '♧'],
    ['label' => 'الموظفون', 'value' => $stats['staff'], 'icon' => '♜'],
    ['label' => 'المقررات الدراسية', 'value' => $stats['courses'], 'icon' => '▦'],
    ['label' => 'القاعات الدراسية', 'value' => $stats['classrooms'], 'icon' => '▤'],
    ['label' => 'الطلبات الجامعية', 'value' => $stats['requests'], 'icon' => '✉'],
    ['label' => 'الواجبات والتكليفات', 'value' => $stats['assignments'], 'icon' => '✎'],
    ['label' => 'سجلات الحضور', 'value' => $stats['attendance'], 'icon' => '✓'],
    ['label' => 'الإشعارات', 'value' => $stats['notifications'], 'icon' => '♧'],
    ['label' => 'التنبيهات الأكاديمية', 'value' => $stats['alerts'], 'icon' => '⚑']
];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#102c46">
<title>لوحة التحكم | S-UCMS</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap');

:root {
    --navy: #102c46;
    --navy-light: #183d5c;
    --blue: #2675b9;
    --gold: #d6a84b;
    --text: #243447;
    --muted: #8190a1;
    --border: #e7edf3;
    --background: #f3f6fa;
    --white: #ffffff;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Cairo', sans-serif;
    background: var(--background);
    color: var(--text);
    min-height: 100vh;
}

button {
    font-family: inherit;
}

.layout {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: 265px;
    background: var(--navy);
    color: white;
    padding: 27px 18px;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 5px 9px 30px;
    border-bottom: 1px solid rgba(255,255,255,.12);
}

.brand-icon {
    width: 45px;
    height: 45px;
    border-radius: 13px;
    background: rgba(255,255,255,.13);
    color: #f1c66c;
    display: grid;
    place-items: center;
    font-size: 25px;
    font-weight: 900;
}

.brand-name {
    font-size: 19px;
    font-weight: 900;
}

.brand-subtitle {
    color: #a9bfd0;
    font-size: 9px;
    letter-spacing: .5px;
}

.side-label {
    color: #8fa8bb;
    font-size: 10px;
    margin: 27px 12px 12px;
}

.side-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #d4e0e9;
    text-decoration: none;
    padding: 12px 13px;
    border-radius: 10px;
    font-size: 12px;
    margin-bottom: 5px;
}

.side-link.active {
    background: rgba(255,255,255,.13);
    color: #f1c66c;
}

.side-link .side-icon {
    width: 22px;
    text-align: center;
    font-size: 17px;
}

.sidebar-bottom {
    margin-top: auto;
    padding-top: 25px;
}

.sidebar-user {
    border-top: 1px solid rgba(255,255,255,.12);
    padding: 19px 8px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: #285878;
    display: grid;
    place-items: center;
    color: #f1c66c;
    font-weight: 800;
}

.sidebar-user-name {
    font-size: 11px;
    font-weight: 700;
}

.sidebar-user-role {
    color: #a9bfd0;
    font-size: 10px;
}

.main {
    flex: 1;
    min-width: 0;
}

.topbar {
    min-height: 78px;
    background: white;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 35px;
    gap: 15px;
}

.topbar-title {
    color: var(--navy);
    font-size: 17px;
    font-weight: 800;
}

.topbar-subtitle {
    color: var(--muted);
    font-size: 10px;
    margin-top: 3px;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 18px;
}

.date {
    color: var(--muted);
    font-size: 11px;
}

.logout-button {
    background: #fff3f1;
    border: 1px solid #f3d9d4;
    color: #bd5147;
    padding: 9px 15px;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.content {
    padding: 30px 35px 40px;
    max-width: 1500px;
    margin: auto;
}

.welcome {
    background: linear-gradient(115deg, #102c46, #1b5277);
    color: white;
    border-radius: 17px;
    padding: 29px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    position: relative;
    overflow: hidden;
}

.welcome::after {
    content: "";
    position: absolute;
    width: 230px;
    height: 230px;
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 50%;
    left: 8%;
    top: -130px;
}

.welcome-label {
    color: #f1c66c;
    font-size: 11px;
    margin-bottom: 8px;
}

.welcome h1 {
    font-size: 23px;
    font-weight: 800;
    margin-bottom: 7px;
}

.welcome p {
    color: #d1e0eb;
    font-size: 11px;
    line-height: 2;
}

.welcome-symbol {
    width: 72px;
    height: 72px;
    border-radius: 19px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.16);
    display: grid;
    place-items: center;
    font-size: 34px;
    color: #f1c66c;
    flex-shrink: 0;
}

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 30px 0 16px;
}

.section-heading h2 {
    font-size: 15px;
    color: var(--navy);
    font-weight: 800;
}

.section-heading span {
    color: var(--muted);
    font-size: 10px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 13px;
}

.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 17px 15px;
    min-width: 0;
}

.stat-icon {
    width: 37px;
    height: 37px;
    border-radius: 10px;
    background: #eaf3fb;
    color: var(--blue);
    display: grid;
    place-items: center;
    font-size: 18px;
    margin-bottom: 12px;
}

.stat-value {
    color: var(--navy);
    font-size: 24px;
    font-weight: 900;
    line-height: 1.5;
}

.stat-label {
    color: var(--muted);
    font-size: 10px;
    margin-top: 4px;
}

.services-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
}

.service-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 19px;
    transition: .2s;
}

.service-card:hover {
    border-color: #b9d2e7;
    transform: translateY(-2px);
    box-shadow: 0 7px 20px rgba(25,65,100,.06);
}

.service-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    background: #eaf3fb;
    color: var(--blue);
    display: grid;
    place-items: center;
    font-size: 21px;
    margin-bottom: 13px;
}

.service-card h3 {
    font-size: 12px;
    color: var(--navy);
    margin-bottom: 6px;
}

.service-card p {
    color: var(--muted);
    font-size: 10px;
    line-height: 1.9;
}

.profile-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 20px;
    margin-top: 25px;
}

.profile-card h3 {
    font-size: 13px;
    color: var(--navy);
    margin-bottom: 15px;
}

.profile-details {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.profile-item span {
    display: block;
    color: var(--muted);
    font-size: 10px;
    margin-bottom: 5px;
}

.profile-item strong {
    color: var(--text);
    font-size: 11px;
    overflow-wrap: anywhere;
}

.footer {
    text-align: center;
    color: #9aa7b5;
    font-size: 10px;
    padding: 30px 10px 10px;
}

@media (max-width: 1100px) {
    .stats-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .services-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 750px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 13px 15px;
    }

    .brand {
        padding: 0 5px 12px;
        border: 0;
    }

    .sidebar .side-label,
    .sidebar .side-link,
    .sidebar-bottom {
        display: none;
    }

    .topbar {
        min-height: 65px;
        padding: 12px 17px;
    }

    .topbar-title {
        font-size: 14px;
    }

    .date {
        display: none;
    }

    .content {
        padding: 20px 15px 30px;
    }

    .welcome {
        padding: 23px 19px;
    }

    .welcome h1 {
        font-size: 19px;
    }

    .welcome p {
        font-size: 10px;
    }

    .welcome-symbol {
        width: 53px;
        height: 53px;
        font-size: 25px;
    }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .services-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .service-card {
        padding: 14px;
    }

    .profile-details {
        grid-template-columns: 1fr;
        gap: 13px;
    }
}

@media (max-width: 370px) {
    .services-grid {
        grid-template-columns: 1fr;
    }

    .topbar-left {
        gap: 7px;
    }

    .logout-button {
        padding: 8px 10px;
    }
}
</style>
</head>
<body>

<div class="layout">

    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon">U</div>
            <div>
                <div class="brand-name">S-UCMS</div>
                <div class="brand-subtitle">SMART UNIVERSITY CAMPUS MANAGEMENT</div>
            </div>
        </div>

        <div class="side-label">القائمة الرئيسية</div>

        <a href="#overview" class="side-link active">
            <span class="side-icon">⌂</span>
            <span>الرئيسية</span>
        </a>

        <a href="#services" class="side-link">
            <span class="side-icon">▦</span>
            <span>الخدمات والأنظمة</span>
        </a>

        <a href="#profile" class="side-link">
            <span class="side-icon">♙</span>
            <span>الملف الشخصي</span>
        </a>

        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <div class="avatar">
                    <?= htmlspecialchars(mb_substr($fullName, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div>
                    <div class="sidebar-user-name">
                        <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="sidebar-user-role">
                        <?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <div>
                <div class="topbar-title"><?= htmlspecialchars($content['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="topbar-subtitle">نظام إدارة الحرم الجامعي الذكي المتكامل</div>
            </div>

            <div class="topbar-left">
                <div class="date">
                    <?= date('Y/m/d') ?>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" name="logout" value="1" class="logout-button">
                        تسجيل الخروج
                    </button>
                </form>
            </div>
        </header>

        <div class="content" id="overview">

            <section class="welcome">
                <div>
                    <div class="welcome-label">النظام الجامعي الذكي المتكامل</div>
                    <h1>مرحبا، <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p><?= htmlspecialchars($content['description'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="welcome-symbol">U</div>
            </section>

            <?php if ($role === 'administrator'): ?>

                <div class="section-heading">
                    <h2>مؤشرات النظام</h2>
                    <span>إحصائيات من قاعدة البيانات</span>
                </div>

                <section class="stats-grid">
                    <?php foreach ($adminStats as $stat): ?>
                        <div class="stat-card">
                            <div class="stat-icon"><?= $stat['icon'] ?></div>
                            <div class="stat-value"><?= number_format($stat['value']) ?></div>
                            <div class="stat-label"><?= htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                </section>

            <?php endif; ?>

            <div class="section-heading" id="services">
                <h2>الخدمات والأنظمة</h2>
                <span><?= count($content['items']) ?> خدمات</span>
            </div>

            <section class="services-grid">
                <?php foreach ($content['items'] as $item): ?>
                    <article class="service-card">
                        <div class="service-icon"><?= $item['icon'] ?></div>
                        <h3><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <p><?= htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </article>
                <?php endforeach; ?>
            </section>

            <section class="profile-card" id="profile">
                <h3>معلومات الحساب</h3>

                <div class="profile-details">
                    <div class="profile-item">
                        <span>الاسم الكامل</span>
                        <strong><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>

                    <div class="profile-item">
                        <span>البريد الإلكتروني</span>
                        <strong><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>

                    <div class="profile-item">
                        <span>نوع الحساب</span>
                        <strong><?= htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>
            </section>

            <footer class="footer">
                S-UCMS © 2026 - جميع الحقوق محفوظة
            </footer>

        </div>
    </main>
</div>

</body>
</html>
                       