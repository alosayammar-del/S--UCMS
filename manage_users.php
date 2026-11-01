<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    exit('غير مصرح لك بالوصول إلى هذه الصفحة.');
}

$userId = (int) $_SESSION['user_id'];

$checkAdmin = $pdo->prepare(
    "SELECT id, full_name, email, role, status
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$checkAdmin->execute([$userId]);
$admin = $checkAdmin->fetch();

if (!$admin || $admin['role'] !== 'administrator' || $admin['status'] !== 'active') {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

$roleLabels = [
    'student' => 'طالب',
    'faculty' => 'عضو هيئة تدريس',
    'staff' => 'موظف',
    'administrator' => 'مسؤول النظام'
];

$statusLabels = [
    'active' => 'نشط',
    'inactive' => 'غير نشط',
    'suspended' => 'موقوف'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['users_csrf'], $csrfToken)) {
        $error = 'انتهت صلاحية الطلب. أعد تحميل الصفحة وحاول مرة أخرى.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? '';

            $studentNumber = trim($_POST['student_number'] ?? '');
            $studentDepartment = trim($_POST['student_department'] ?? '');
            $academicLevel = trim($_POST['academic_level'] ?? '');

            $employeeNumber = trim($_POST['employee_number'] ?? '');
            $facultyDepartment = trim($_POST['faculty_department'] ?? '');
            $academicTitle = trim($_POST['academic_title'] ?? '');

            if ($fullName === '' || $email === '' || $password === '') {
                $error = 'يرجى تعبئة جميع الحقول المطلوبة.';
            } elseif (mb_strlen($fullName, 'UTF-8') > 150) {
                $error = 'الاسم الكامل أطول من الحد المسموح.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'يرجى إدخال بريد إلكتروني صحيح.';
            } elseif (strlen($email) > 150) {
                $error = 'البريد الإلكتروني أطول من الحد المسموح.';
            } elseif ($phone !== '' && strlen($phone) > 20) {
                $error = 'رقم الهاتف أطول من الحد المسموح.';
            } elseif (strlen($password) < 12) {
                $error = 'يجب أن تتكون كلمة المرور من 12 حرفا على الأقل.';
            } elseif (!in_array($role, ['student', 'faculty', 'staff'], true)) {
                $error = 'نوع الحساب المحدد غير صالح.';
            } elseif ($role === 'student' && $studentNumber === '') {
                $error = 'يرجى إدخال الرقم الجامعي للطالب.';
            } elseif ($role === 'faculty' && $employeeNumber === '') {
                $error = 'يرجى إدخال الرقم الوظيفي لعضو هيئة التدريس.';
            } elseif (strlen($studentNumber) > 50 || strlen($employeeNumber) > 50) {
                $error = 'الرقم الجامعي أو الوظيفي أطول من الحد المسموح.';
            } elseif (mb_strlen($studentDepartment, 'UTF-8') > 100 || mb_strlen($facultyDepartment, 'UTF-8') > 100) {
                $error = 'اسم القسم أطول من الحد المسموح.';
            } elseif (mb_strlen($academicLevel, 'UTF-8') > 50) {
                $error = 'المستوى الأكاديمي أطول من الحد المسموح.';
            } elseif (mb_strlen($academicTitle, 'UTF-8') > 100) {
                $error = 'اللقب الأكاديمي أطول من الحد المسموح.';
            } else {
                try {
                    $checkEmail = $pdo->prepare(
                        "SELECT id FROM users WHERE email = ? LIMIT 1"
                    );
                    $checkEmail->execute([$email]);

                    if ($checkEmail->fetch()) {
                        $error = 'هذا البريد الإلكتروني مسجل بالفعل.';
                    } else {
                        $pdo->beginTransaction();

                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        $insert = $pdo->prepare(
                            "INSERT INTO users
                            (full_name, email, phone, password_hash, role, status)
                            VALUES (?, ?, ?, ?, ?, 'active')"
                        );

                        $insert->execute([
                            $fullName,
                            $email,
                            $phone !== '' ? $phone : null,
                            $passwordHash,
                            $role
                        ]);

                        $newUserId = (int) $pdo->lastInsertId();

                        if ($role === 'student') {
                            $insertStudent = $pdo->prepare(
                                "INSERT INTO students
                                (user_id, student_number, department, academic_level)
                                VALUES (?, ?, ?, ?)"
                            );

                            $insertStudent->execute([
                                $newUserId,
                                $studentNumber,
                                $studentDepartment !== '' ? $studentDepartment : null,
                                $academicLevel !== '' ? $academicLevel : null
                            ]);
                        } elseif ($role === 'faculty') {
                            $insertFaculty = $pdo->prepare(
                                "INSERT INTO faculty_members
                                (user_id, employee_number, department, academic_title)
                                VALUES (?, ?, ?, ?)"
                            );

                            $insertFaculty->execute([
                                $newUserId,
                                $employeeNumber,
                                $facultyDepartment !== '' ? $facultyDepartment : null,
                                $academicTitle !== '' ? $academicTitle : null
                            ]);
                        }

                        $pdo->commit();

                        $success = 'تم إنشاء الحساب وربط بياناته بنجاح.';
                        $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log($e->getMessage());

                    if ($e->getCode() === '23000') {
                        if ($role === 'student') {
                            $error = 'الرقم الجامعي مستخدم بالفعل أو توجد بيانات مكررة.';
                        } elseif ($role === 'faculty') {
                            $error = 'الرقم الوظيفي مستخدم بالفعل أو توجد بيانات مكررة.';
                        } else {
                            $error = 'توجد بيانات مكررة تمنع إنشاء الحساب.';
                        }
                    } else {
                        $error = 'تعذر إنشاء الحساب. يرجى التحقق من البيانات والمحاولة مرة أخرى.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log($e->getMessage());
                    $error = 'حدث خطأ أثناء إنشاء الحساب.';
                }
            }
        } elseif ($action === 'update_status') {
            $targetId = filter_var(
                $_POST['user_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $newStatus = $_POST['status'] ?? '';

            if (!$targetId || $targetId === $userId) {
                $error = 'لا يمكن تغيير حالة حسابك من هذه الصفحة.';
            } elseif (!in_array($newStatus, ['active', 'inactive', 'suspended'], true)) {
                $error = 'حالة الحساب المحددة غير صالحة.';
            } else {
                try {
                    $targetQuery = $pdo->prepare(
                        "SELECT id, role FROM users WHERE id = ? LIMIT 1"
                    );
                    $targetQuery->execute([$targetId]);
                    $target = $targetQuery->fetch();

                    if (!$target) {
                        $error = 'الحساب المطلوب غير موجود.';
                    } elseif ($target['role'] === 'administrator') {
                        $error = 'لا يمكن تغيير حالة حساب مسؤول من هذه الصفحة.';
                    } else {
                        $update = $pdo->prepare(
                            "UPDATE users SET status = ? WHERE id = ?"
                        );
                        $update->execute([$newStatus, $targetId]);

                        $success = 'تم تحديث حالة الحساب بنجاح.';
                    }
                } catch (PDOException $e) {
                    error_log($e->getMessage());
                    $error = 'تعذر تحديث حالة الحساب.';
                }
            }
        } else {
            $error = 'العملية المطلوبة غير معروفة.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = "SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            u.role,
            u.status,
            u.created_at,
            s.student_number,
            f.employee_number
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN faculty_members f ON f.user_id = u.id
        WHERE 1 = 1";

$params = [];

if ($search !== '') {
    $sql .= " AND (
        u.full_name LIKE ?
        OR u.email LIKE ?
        OR u.phone LIKE ?
        OR s.student_number LIKE ?
        OR f.employee_number LIKE ?
    )";

    $term = '%' . $search . '%';

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (in_array($filterRole, ['student', 'faculty', 'staff', 'administrator'], true)) {
    $sql .= " AND u.role = ?";
    $params[] = $filterRole;
}

if (in_array($filterStatus, ['active', 'inactive', 'suspended'], true)) {
    $sql .= " AND u.status = ?";
    $params[] = $filterStatus;
}

$sql .= " ORDER BY u.id DESC";

try {
    $listQuery = $pdo->prepare($sql);
    $listQuery->execute($params);
    $users = $listQuery->fetchAll();

    $totalUsers = (int) $pdo->query(
        "SELECT COUNT(*) FROM users"
    )->fetchColumn();

    $activeUsers = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE status = 'active'"
    )->fetchColumn();

    $studentCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'student'"
    )->fetchColumn();

    $facultyCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'faculty'"
    )->fetchColumn();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $users = [];
    $totalUsers = 0;
    $activeUsers = 0;
    $studentCount = 0;
    $facultyCount = 0;
    $error = 'تعذر تحميل بيانات المستخدمين.';
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#102c46">
<title>إدارة المستخدمين | S-UCMS</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800;900&display=swap');

:root {
    --navy: #102c46;
    --blue: #2675b9;
    --gold: #d6a84b;
    --text: #243447;
    --muted: #8190a1;
    --border: #e5ebf1;
    --background: #f3f6fa;
    --white: #ffffff;
    --green: #23845c;
    --red: #c74343;
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

button, input, select {
    font-family: inherit;
}

.layout {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: 250px;
    background: var(--navy);
    color: white;
    padding: 25px 17px;
    flex-shrink: 0;
}

.brand {
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 5px 8px 25px;
    border-bottom: 1px solid rgba(255,255,255,.12);
}

.brand-icon {
    width: 44px;
    height: 44px;
    border-radius: 13px;
    background: rgba(255,255,255,.13);
    color: #f1c66c;
    display: grid;
    place-items: center;
    font-size: 24px;
    font-weight: 900;
}

.brand-name {
    font-size: 18px;
    font-weight: 900;
}

.brand-subtitle {
    font-size: 9px;
    color: #a9bfd0;
}

.sidebar-label {
    color: #8fa8bb;
    font-size: 10px;
    margin: 27px 12px 12px;
}

.side-link {
    display: block;
    text-decoration: none;
    color: #d4e0e9;
    padding: 12px;
    border-radius: 9px;
    font-size: 12px;
    margin-bottom: 5px;
}

.side-link.active {
    background: rgba(255,255,255,.13);
    color: #f1c66c;
}

.main {
    flex: 1;
    min-width: 0;
}

.topbar {
    min-height: 75px;
    background: white;
    border-bottom: 1px solid var(--border);
    padding: 13px 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.topbar h1 {
    color: var(--navy);
    font-size: 17px;
    font-weight: 800;
}

.topbar p {
    color: var(--muted);
    font-size: 10px;
    margin-top: 3px;
}

.back-link {
    text-decoration: none;
    background: #eaf3fb;
    color: var(--blue);
    border-radius: 9px;
    padding: 9px 13px;
    font-size: 11px;
    font-weight: 700;
}

.content {
    max-width: 1450px;
    margin: auto;
    padding: 27px 30px 40px;
}

.welcome {
    background: linear-gradient(115deg, #102c46, #1b5277);
    color: white;
    padding: 25px 27px;
    border-radius: 15px;
    margin-bottom: 24px;
}

.welcome span {
    color: #f1c66c;
    font-size: 11px;
}

.welcome h2 {
    font-size: 21px;
    margin: 7px 0;
}

.welcome p {
    color: #d1e0eb;
    font-size: 11px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
    margin-bottom: 25px;
}

.stat {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 17px;
}

.stat-label {
    color: var(--muted);
    font-size: 10px;
}

.stat-value {
    color: var(--navy);
    font-size: 25px;
    font-weight: 900;
    margin-top: 5px;
}

.section-title {
    color: var(--navy);
    font-size: 15px;
    font-weight: 800;
    margin: 24px 0 14px;
}

.panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 22px;
    margin-bottom: 22px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.form-group label {
    display: block;
    color: #34465a;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 7px;
}

.form-group input,
.form-group select,
.search-form input,
.search-form select {
    width: 100%;
    height: 45px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #fbfcfe;
    padding: 0 12px;
    font-size: 11px;
    outline: none;
    color: var(--text);
}

.form-group input:focus,
.form-group select:focus,
.search-form input:focus,
.search-form select:focus {
    border-color: var(--blue);
}

.full-width {
    grid-column: 1 / -1;
}

.profile-fields {
    display: none;
    grid-column: 1 / -1;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    padding: 17px;
    background: #f5f8fb;
    border: 1px solid var(--border);
    border-radius: 10px;
}

.profile-fields.visible {
    display: grid;
}

.profile-heading {
    grid-column: 1 / -1;
    color: var(--blue);
    font-size: 12px;
    font-weight: 800;
}

.primary-button {
    border: 0;
    background: linear-gradient(100deg, #174b73, #2675b9);
    color: white;
    border-radius: 9px;
    height: 47px;
    padding: 0 23px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}

.alert {
    padding: 12px 15px;
    border-radius: 9px;
    font-size: 11px;
    line-height: 1.8;
    margin-bottom: 17px;
}

.alert.error {
    color: var(--red);
    background: #fff0f0;
    border: 1px solid #f3cccc;
}

.alert.success {
    color: var(--green);
    background: #edf8f2;
    border: 1px solid #cdebd9;
}

.search-form {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 10px;
    margin-bottom: 18px;
}

.search-button {
    border: 0;
    background: var(--navy);
    color: white;
    border-radius: 8px;
    padding: 0 17px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th {
    background: #f5f8fb;
    color: #657487;
    font-size: 10px;
    font-weight: 800;
    padding: 14px 11px;
    text-align: right;
    white-space: nowrap;
}

td {
    border-bottom: 1px solid #edf1f5;
    padding: 13px 11px;
    font-size: 10px;
    color: var(--text);
    white-space: nowrap;
}

.status {
    display: inline-block;
    border-radius: 20px;
    padding: 5px 10px;
    font-size: 9px;
    font-weight: 700;
}

.status.active {
    background: #eaf7f0;
    color: #23845c;
}

.status.inactive {
    background: #f1f3f5;
    color: #697887;
}

.status.suspended {
    background: #fff0f0;
    color: #c74343;
}

.role {
    color: var(--blue);
    font-weight: 700;
}

.action-form {
    display: flex;
    align-items: center;
    gap: 6px;
}

.action-form select {
    height: 34px;
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 0 5px;
    font-family: inherit;
    font-size: 9px;
}

.action-form button {
    height: 34px;
    border: 0;
    border-radius: 7px;
    background: #eaf3fb;
    color: var(--blue);
    padding: 0 9px;
    font-size: 9px;
    font-weight: 700;
    cursor: pointer;
}

.empty {
    text-align: center;
    color: var(--muted);
    padding: 28px;
    font-size: 11px;
}

.footer {
    text-align: center;
    color: #9aa7b5;
    font-size: 10px;
    padding: 25px 10px;
}

@media(max-width: 950px) {
    .sidebar {
        width: 205px;
    }

    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 700px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 12px 15px;
    }

    .brand {
        border: 0;
        padding: 0;
    }

    .sidebar-label,
    .side-link {
        display: none;
    }

    .topbar {
        padding: 12px 15px;
        min-height: 65px;
    }

    .topbar h1 {
        font-size: 14px;
    }

    .content {
        padding: 18px 13px 30px;
    }

    .welcome {
        padding: 20px;
    }

    .welcome h2 {
        font-size: 18px;
    }

    .stats {
        gap: 9px;
    }

    .stat {
        padding: 13px;
    }

    .stat-value {
        font-size: 22px;
    }

    .panel {
        padding: 15px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 13px;
    }

    .full-width {
        grid-column: auto;
    }

    .profile-fields {
        grid-template-columns: 1fr;
    }

    .profile-heading {
        grid-column: auto;
    }

    .search-form {
        grid-template-columns: 1fr 1fr;
    }

    .search-form input {
        grid-column: 1 / -1;
    }

    .search-button {
        height: 43px;
    }
}

@media(max-width: 370px) {
    .search-form {
        grid-template-columns: 1fr;
    }

    .search-form input {
        grid-column: auto;
    }

    .stats {
        grid-template-columns: 1fr 1fr;
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

    <div class="sidebar-label">القائمة الرئيسية</div>
    <a href="dashboard.php" class="side-link">⌂ الرئيسية</a>
    <a href="manage_users.php" class="side-link active">♙ إدارة المستخدمين</a>
</aside>

<main class="main">

<header class="topbar">
    <div>
        <h1>إدارة المستخدمين</h1>
        <p>إدارة حسابات وصلاحيات مستخدمي النظام</p>
    </div>

    <a href="dashboard.php" class="back-link">العودة للوحة التحكم</a>
</header>

<div class="content">

<section class="welcome">
    <span>إدارة النظام</span>
    <h2>إدارة المستخدمين والحسابات</h2>
    <p>إنشاء الحسابات ومتابعة حالتها وتنظيم صلاحيات الوصول.</p>
</section>

<?php if ($error !== ''): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success !== ''): ?>
    <div class="alert success"><?= e($success) ?></div>
<?php endif; ?>

<section class="stats">
    <div class="stat">
        <div class="stat-label">إجمالي الحسابات</div>
        <div class="stat-value"><?= number_format($totalUsers) ?></div>
    </div>

    <div class="stat">
        <div class="stat-label">الحسابات النشطة</div>
        <div class="stat-value"><?= number_format($activeUsers) ?></div>
    </div>

    <div class="stat">
        <div class="stat-label">حسابات الطلاب</div>
        <div class="stat-value"><?= number_format($studentCount) ?></div>
    </div>

    <div class="stat">
        <div class="stat-label">أعضاء هيئة التدريس</div>
        <div class="stat-value"><?= number_format($facultyCount) ?></div>
    </div>
</section>

<h2 class="section-title">إنشاء حساب جديد</h2>

<section class="panel">

<form method="POST" action="manage_users.php" class="form-grid">

    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['users_csrf']) ?>">
    <input type="hidden" name="action" value="create">

    <div class="form-group">
        <label for="full_name">الاسم الكامل</label>
        <input type="text" id="full_name" name="full_name" maxlength="150" required>
    </div>

    <div class="form-group">
        <label for="email">البريد الإلكتروني</label>
        <input type="email" id="email" name="email" maxlength="150" required>
    </div>

    <div class="form-group">
        <label for="phone">رقم الهاتف</label>
        <input type="tel" id="phone" name="phone" maxlength="20">
    </div>

    <div class="form-group">
        <label for="role">نوع الحساب</label>
        <select id="role" name="role" required>
            <option value="">اختر نوع الحساب</option>
            <option value="student">طالب</option>
            <option value="faculty">عضو هيئة تدريس</option>
            <option value="staff">موظف</option>
        </select>
    </div>

    <div id="studentFields" class="profile-fields">
        <div class="profile-heading">بيانات الطالب</div>

        <div class="form-group">
            <label for="student_number">الرقم الجامعي</label>
            <input type="text" id="student_number" name="student_number" maxlength="50">
        </div>

        <div class="form-group">
            <label for="student_department">القسم</label>
            <input type="text" id="student_department" name="student_department" maxlength="100">
        </div>

        <div class="form-group">
            <label for="academic_level">المستوى الأكاديمي</label>
            <input type="text" id="academic_level" name="academic_level" maxlength="50">
        </div>
    </div>

    <div id="facultyFields" class="profile-fields">
        <div class="profile-heading">بيانات عضو هيئة التدريس</div>

        <div class="form-group">
            <label for="employee_number">الرقم الوظيفي</label>
            <input type="text" id="employee_number" name="employee_number" maxlength="50">
        </div>

        <div class="form-group">
            <label for="faculty_department">القسم</label>
            <input type="text" id="faculty_department" name="faculty_department" maxlength="100">
        </div>

        <div class="form-group">
            <label for="academic_title">اللقب الأكاديمي</label>
            <input type="text" id="academic_title" name="academic_title" maxlength="100">
        </div>
    </div>

    <div class="form-group full-width">
        <label for="password">كلمة المرور</label>
        <input type="password" id="password" name="password" minlength="12" autocomplete="new-password" required>
    </div>

    <div class="full-width">
        <button type="submit" class="primary-button">إنشاء الحساب</button>
    </div>

</form>

</section>

<h2 class="section-title">قائمة المستخدمين</h2>

<section class="panel">

<form method="GET" action="manage_users.php" class="search-form">

    <input
        type="search"
        name="search"
        placeholder="ابحث بالاسم أو البريد أو الهاتف أو الرقم"
        value="<?= e($search) ?>"
    >

    <select name="role">
        <option value="">جميع الأدوار</option>
        <option value="student" <?= $filterRole === 'student' ? 'selected' : '' ?>>طالب</option>
        <option value="faculty" <?= $filterRole === 'faculty' ? 'selected' : '' ?>>عضو هيئة تدريس</option>
        <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>موظف</option>
        <option value="administrator" <?= $filterRole === 'administrator' ? 'selected' : '' ?>>مسؤول النظام</option>
    </select>

    <select name="status">
        <option value="">جميع الحالات</option>
        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>نشط</option>
        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>غير نشط</option>
        <option value="suspended" <?= $filterStatus === 'suspended' ? 'selected' : '' ?>>موقوف</option>
    </select>

    <button type="submit" class="search-button">بحث</button>

</form>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th>رقم الحساب</th>
    <th>الاسم الكامل</th>
    <th>البريد الإلكتروني</th>
    <th>الهاتف</th>
    <th>نوع الحساب</th>
    <th>الرقم الجامعي / الوظيفي</th>
    <th>الحالة</th>
    <th>تاريخ الإنشاء</th>
    <th>الإجراء</th>
</tr>
</thead>

<tbody>

<?php if (count($users) > 0): ?>

    <?php foreach ($users as $account): ?>

    <tr>
        <td><?= (int) $account['id'] ?></td>

        <td><?= e($account['full_name']) ?></td>

        <td><?= e($account['email']) ?></td>

        <td><?= e($account['phone'] ?? '—') ?></td>

        <td class="role">
            <?= e($roleLabels[$account['role']] ?? $account['role']) ?>
        </td>

        <td>
            <?php
            if ($account['role'] === 'student') {
                echo e($account['student_number'] ?? '—');
            } elseif ($account['role'] === 'faculty') {
                echo e($account['employee_number'] ?? '—');
            } else {
                echo '—';
            }
            ?>
        </td>

        <td>
            <span class="status <?= e($account['status']) ?>">
                <?= e($statusLabels[$account['status']] ?? $account['status']) ?>
            </span>
        </td>

        <td><?= e($account['created_at']) ?></td>

        <td>

        <?php if ($account['role'] !== 'administrator' && (int) $account['id'] !== $userId): ?>

            <form method="POST" action="manage_users.php" class="action-form">

                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['users_csrf']) ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="user_id" value="<?= (int) $account['id'] ?>">

                <select name="status">
                    <option value="active" <?= $account['status'] === 'active' ? 'selected' : '' ?>>نشط</option>
                    <option value="inactive" <?= $account['status'] === 'inactive' ? 'selected' : '' ?>>غير نشط</option>
                    <option value="suspended" <?= $account['status'] === 'suspended' ? 'selected' : '' ?>>موقوف</option>
                </select>

                <button type="submit">حفظ</button>

            </form>

        <?php else: ?>

            <span>محمي</span>

        <?php endif; ?>

        </td>
    </tr>

    <?php endforeach; ?>

<?php else: ?>

    <tr>
        <td colspan="9" class="empty">لا توجد حسابات مطابقة لعملية البحث.</td>
    </tr>

<?php endif; ?>

</tbody>
</table>
</div>

</section>

<footer class="footer">
    S-UCMS © 2026 - جميع الحقوق محفوظة
</footer>

</div>
</main>
</div>

<script>
const roleSelect = document.getElementById('role');
const studentFields = document.getElementById('studentFields');
const facultyFields = document.getElementById('facultyFields');

const studentNumber = document.getElementById('student_number');
const employeeNumber = document.getElementById('employee_number');

function updateProfileFields() {
    const role = roleSelect.value;

    studentFields.classList.toggle('visible', role === 'student');
    facultyFields.classList.toggle('visible', role === 'faculty');

    studentNumber.required = role === 'student';
    employeeNumber.required = role === 'faculty';

    studentNumber.disabled = role !== 'student';
    document.getElementById('student_department').disabled = role !== 'student';
    document.getElementById('academic_level').disabled = role !== 'student';

    employeeNumber.disabled = role !== 'faculty';
    document.getElementById('faculty_department').disabled = role !== 'faculty';
    document.getElementById('academic_title').disabled = role !== 'faculty';
}

roleSelect.addEventListener('change', updateProfileFields);
updateProfileFields();
</script>

</body>
</html>
