<?php
session_start();

require_once __DIR__ . '/config/database.php';

/* التحقق من صلاحية المدير */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$success = '';
$error = '';

/* حماية CSRF */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* معالجة العمليات */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $token)) {
        $error = 'طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'add') {
                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? '';
                $status = $_POST['status'] ?? 'active';

                $allowedRoles = ['student', 'faculty', 'staff', 'administrator'];
                $allowedStatuses = ['active', 'inactive'];

                if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('يرجى إدخال الاسم والبريد الإلكتروني بشكل صحيح.');
                }

                if (strlen($password) < 8) {
                    throw new Exception('يجب أن تكون كلمة المرور 8 أحرف على الأقل.');
                }

                if (!in_array($role, $allowedRoles, true) ||
                    !in_array($status, $allowedStatuses, true)) {
                    throw new Exception('بيانات الدور أو الحالة غير صحيحة.');
                }

                $studentNumber = trim($_POST['student_number'] ?? '');
                $department = trim($_POST['department'] ?? '');
                $academicLevel = trim($_POST['academic_level'] ?? '');
                $employeeNumber = trim($_POST['employee_number'] ?? '');
                $academicTitle = trim($_POST['academic_title'] ?? '');

                if ($role === 'student' &&
                    ($studentNumber === '' || $department === '' || $academicLevel === '')) {
                    throw new Exception('يرجى إدخال الرقم الجامعي والقسم والمستوى الأكاديمي.');
                }

                if ($role === 'faculty' &&
                    ($employeeNumber === '' || $department === '' || $academicTitle === '')) {
                    throw new Exception('يرجى إدخال الرقم الوظيفي والقسم واللقب الأكاديمي.');
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (full_name, email, phone, password_hash, role, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $fullName,
                    $email,
                    $phone,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $status
                ]);

                $newUserId = (int) $pdo->lastInsertId();

                if ($role === 'student') {
                    $stmt = $pdo->prepare("
                        INSERT INTO students
                        (user_id, student_number, department, academic_level)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $newUserId,
                        $studentNumber,
                        $department,
                        $academicLevel
                    ]);
                }

                if ($role === 'faculty') {
                    $stmt = $pdo->prepare("
                        INSERT INTO faculty_members
                        (user_id, employee_number, department, academic_title)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $newUserId,
                        $employeeNumber,
                        $department,
                        $academicTitle
                    ]);
                }

                $pdo->commit();
                $success = 'تمت إضافة المستخدم بنجاح.';
            }

            elseif ($action === 'edit') {
                $editId = (int) ($_POST['user_id'] ?? 0);
                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $status = $_POST['status'] ?? '';
                $password = $_POST['password'] ?? '';

                if ($editId <= 0 || $fullName === '' ||
                    !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('يرجى التحقق من البيانات المدخلة.');
                }

                if (!in_array($status, ['active', 'inactive'], true)) {
                    throw new Exception('حالة المستخدم غير صحيحة.');
                }

                if ($editId === $userId && $status !== 'active') {
                    throw new Exception('لا يمكنك تعطيل حسابك الحالي.');
                }

                if ($password !== '' && strlen($password) < 8) {
                    throw new Exception('يجب أن تكون كلمة المرور 8 أحرف على الأقل.');
                }

                if ($password !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = ?, email = ?, phone = ?,
                            status = ?, password_hash = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $fullName,
                        $email,
                        $phone,
                        $status,
                        password_hash($password, PASSWORD_DEFAULT),
                        $editId
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET full_name = ?, email = ?, phone = ?, status = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([
                        $fullName,
                        $email,
                        $phone,
                        $status,
                        $editId
                    ]);
                }

                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare("SELECT id FROM users WHERE id = ?");
                    $check->execute([$editId]);

                    if (!$check->fetch()) {
                        throw new Exception('المستخدم غير موجود.');
                    }
                }

                $success = 'تم تحديث بيانات المستخدم بنجاح.';
            }

            elseif ($action === 'toggle_status') {
                $targetId = (int) ($_POST['user_id'] ?? 0);
                $newStatus = $_POST['new_status'] ?? '';

                if ($targetId <= 0 ||
                    !in_array($newStatus, ['active', 'inactive'], true)) {
                    throw new Exception('بيانات العملية غير صحيحة.');
                }

                if ($targetId === $userId && $newStatus === 'inactive') {
                    throw new Exception('لا يمكنك تعطيل حسابك الحالي.');
                }

                $stmt = $pdo->prepare("
                    UPDATE users SET status = ? WHERE id = ?
                ");

                $stmt->execute([$newStatus, $targetId]);

                $success = 'تم تحديث حالة المستخدم بنجاح.';
            }

            else {
                throw new Exception('العملية المطلوبة غير معروفة.');
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === '23000') {
                $error = 'البريد الإلكتروني أو الرقم الجامعي أو الوظيفي مستخدم مسبقا.';
            } else {
                $error = 'حدث خطأ أثناء تنفيذ العملية. تحقق من البيانات وحاول مرة أخرى.';
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

/* البحث والتصفية */
$search = trim($_GET['search'] ?? '');
$filterRole = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        u.role,
        u.status,
        u.created_at,
        s.student_number,
        s.department AS student_department,
        s.academic_level,
        f.employee_number,
        f.department AS faculty_department,
        f.academic_title
    FROM users u
    LEFT JOIN students s ON s.user_id = u.id
    LEFT JOIN faculty_members f ON f.user_id = u.id
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR s.student_number LIKE ?
            OR f.employee_number LIKE ?
        )
    ";

    $keyword = "%{$search}%";
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
}

if (in_array($filterRole, ['student', 'faculty', 'staff', 'administrator'], true)) {
    $sql .= " AND u.role = ?";
    $params[] = $filterRole;
}

if (in_array($filterStatus, ['active', 'inactive'], true)) {
    $sql .= " AND u.status = ?";
    $params[] = $filterStatus;
}

$sql .= " ORDER BY u.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* الإحصائيات */
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
        SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS students_count,
        SUM(CASE WHEN role = 'faculty' THEN 1 ELSE 0 END) AS faculty_count,
        SUM(CASE WHEN role = 'staff' THEN 1 ELSE 0 END) AS staff_count,
        SUM(CASE WHEN role = 'administrator' THEN 1 ELSE 0 END) AS admins_count
    FROM users
");

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$roleLabels = [
    'student' => 'طالب',
    'faculty' => 'عضو هيئة تدريس',
    'staff' => 'موظف',
    'administrator' => 'مدير النظام'
];

$editUser = null;
if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    foreach ($users as $user) {
        if ((int)$user['id'] === $editId) {
            $editUser = $user;
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Tahoma, Arial, sans-serif;
        }

        body {
            background: #f4f6fa;
            color: #263445;
        }

        .container {
            width: 94%;
            max-width: 1250px;
            margin: 30px auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .topbar h1 {
            color: #17365d;
            font-size: 25px;
            margin-bottom: 7px;
        }

        .topbar p {
            color: #758195;
            font-size: 14px;
        }

        .back {
            text-decoration: none;
            color: white;
            background: #17365d;
            padding: 11px 18px;
            border-radius: 8px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
            border-right: 4px solid #1c6e8c;
        }

        .stat-card span {
            color: #788497;
            font-size: 13px;
        }

        .stat-card h2 {
            margin-top: 10px;
            color: #17365d;
            font-size: 25px;
        }

        .panel {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .panel h2 {
            color: #17365d;
            font-size: 19px;
            margin-bottom: 18px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce2ea;
            border-radius: 7px;
            outline: none;
            background: white;
            font-size: 14px;
        }

        input:focus, select:focus {
            border-color: #1c6e8c;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        button, .cancel {
            border: none;
            padding: 11px 18px;
            border-radius: 7px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }

        .primary {
            background: #17365d;
            color: white;
        }

        .secondary {
            background: #e8edf4;
            color: #263445;
        }

        .edit-btn {
            background: #e7f1ff;
            color: #17539b;
            padding: 7px 10px;
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
        }

        .status-btn {
            padding: 7px 10px;
            border-radius: 6px;
            font-size: 12px;
        }

        .disable-btn {
            background: #fff0ed;
            color: #bd3e2d;
        }

        .enable-btn {
            background: #e8f8ee;
            color: #16804a;
        }

        .message {
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .success {
            background: #e8f8ee;
            color: #167344;
        }

        .error {
            background: #fff0ed;
            color: #b83226;
        }

        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 18px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 13px 11px;
            text-align: right;
            border-bottom: 1px solid #edf0f4;
            font-size: 13px;
        }

        th {
            background: #f5f7fa;
            color: #526176;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
        }

        .active {
            background: #e7f7ed;
            color: #17804a;
        }

        .inactive {
            background: #fff0ed;
            color: #b83226;
        }

        .role {
            background: #edf3fa;
            color: #315579;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #8792a2;
        }

        .form-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        @media(max-width: 700px) {
            .form-grid, .filters {
                grid-template-columns: 1fr;
            }

            .container {
                width: 95%;
                margin: 18px auto;
            }

            .panel {
                padding: 16px;
            }

            .topbar h1 {
                font-size: 21px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="topbar">
        <div>
            <h1>إدارة المستخدمين</h1>
            <p>إضافة المستخدمين وتعديل بياناتهم وإدارة صلاحيات الدخول إلى النظام.</p>
        </div>

        <a href="dashboard.php" class="back">العودة إلى لوحة التحكم</a>
    </div>

    <?php if ($success): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card">
            <span>إجمالي المستخدمين</span>
            <h2><?= (int)$stats['total'] ?></h2>
        </div>

        <div class="stat-card">
            <span>الحسابات النشطة</span>
            <h2><?= (int)$stats['active_count'] ?></h2>
        </div>

        <div class="stat-card">
            <span>الحسابات المعطلة</span>
            <h2><?= (int)$stats['inactive_count'] ?></h2>
        </div>

        <div class="stat-card">
            <span>الطلاب</span>
            <h2><?= (int)$stats['students_count'] ?></h2>
        </div>

        <div class="stat-card">
            <span>أعضاء هيئة التدريس</span>
            <h2><?= (int)$stats['faculty_count'] ?></h2>
        </div>

        <div class="stat-card">
            <span>الموظفون</span>
            <h2><?= (int)$stats['staff_count'] ?></h2>
        </div>
    </div>

    <div class="panel" id="userForm">

        <h2>
            <?= $editUser ? 'تعديل بيانات المستخدم' : 'إضافة مستخدم جديد' ?>
        </h2>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <?php if ($editUser): ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" value="<?= (int)$editUser['id'] ?>">
            <?php else: ?>
                <input type="hidden" name="action" value="add">
            <?php endif; ?>

            <div class="form-grid">

                <div class="field">
                    <label>الاسم الكامل</label>
                    <input type="text" name="full_name" required
                        value="<?= e($editUser['full_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>البريد الإلكتروني</label>
                    <input type="email" name="email" required
                        value="<?= e($editUser['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>رقم الهاتف</label>
                    <input type="text" name="phone"
                        value="<?= e($editUser['phone'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>كلمة المرور <?= $editUser ? '(اتركها فارغة دون تغيير)' : '' ?></label>
                    <input type="password" name="password"
                        <?= $editUser ? '' : 'required' ?>
                        minlength="8"
                        placeholder="<?= $editUser ? 'كلمة مرور جديدة اختيارية' : '8 أحرف على الأقل' ?>">
                </div>

                <?php if (!$editUser): ?>
                    <div class="field">
                        <label>دور المستخدم</label>
                        <select name="role" id="role" required onchange="showRoleFields()">
                            <option value="">اختر الدور</option>
                            <option value="student">طالب</option>
                            <option value="faculty">عضو هيئة تدريس</option>
                            <option value="staff">موظف</option>
                            <option value="administrator">مدير النظام</option>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label>دور المستخدم</label>
                        <input type="text" readonly
                            value="<?= e($roleLabels[$editUser['role']] ?? $editUser['role']) ?>">
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label>حالة الحساب</label>
                    <select name="status" required>
                        <option value="active"
                            <?= ($editUser['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>
                            نشط
                        </option>
                        <option value="inactive"
                            <?= ($editUser['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>
                            معطل
                        </option>
                    </select>
                </div>

                <?php if (!$editUser): ?>
                    <div class="field role-field" id="studentFields" style="display:none;">
                        <label>الرقم الجامعي</label>
                        <input type="text" name="student_number">
                    </div>

                    <div class="field role-field" id="studentDepartment" style="display:none;">
                        <label>القسم الأكاديمي للطالب</label>
                        <input type="text" name="department">
                    </div>

                    <div class="field role-field" id="studentLevel" style="display:none;">
                        <label>المستوى الأكاديمي</label>
                        <input type="text" name="academic_level">
                    </div>

                    <div class="field role-field" id="facultyFields" style="display:none;">
                        <label>الرقم الوظيفي</label>
                        <input type="text" name="employee_number">
                    </div>

                    <div class="field role-field" id="facultyDepartment" style="display:none;">
                        <label>قسم عضو هيئة التدريس</label>
                        <input type="text" name="faculty_department">
                    </div>

                    <div class="field role-field" id="facultyTitle" style="display:none;">
                        <label>اللقب الأكاديمي</label>
                        <input type="text" name="academic_title">
                    </div>
                <?php endif; ?>

            </div>

            <div class="actions">
                <button type="submit" class="primary">
                    <?= $editUser ? 'حفظ التعديلات' : 'إضافة المستخدم' ?>
                </button>

                <?php if ($editUser): ?>
                    <a href="admin_users.php" class="cancel secondary">إلغاء التعديل</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">

        <h2>قائمة المستخدمين</h2>

        <form method="GET" class="filters">
            <input type="text" name="search"
                placeholder="ابحث بالاسم أو البريد أو الرقم..."
                value="<?= e($search) ?>">

            <select name="role">
                <option value="">جميع الأدوار</option>
                <option value="student" <?= $filterRole === 'student' ? 'selected' : '' ?>>طالب</option>
                <option value="faculty" <?= $filterRole === 'faculty' ? 'selected' : '' ?>>عضو هيئة تدريس</option>
                <option value="staff" <?= $filterRole === 'staff' ? 'selected' : '' ?>>موظف</option>
                <option value="administrator" <?= $filterRole === 'administrator' ? 'selected' : '' ?>>مدير النظام</option>
            </select>

            <select name="status">
                <option value="">جميع الحالات</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>نشط</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>معطل</option>
            </select>

            <button type="submit" class="primary">بحث</button>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>الرقم</th>
                        <th>الاسم</th>
                        <th>البريد الإلكتروني</th>
                        <th>رقم الهاتف</th>
                        <th>الدور</th>
                        <th>الحالة</th>
                        <th>تاريخ الإضافة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (count($users) > 0): ?>

                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= (int)$user['id'] ?></td>

                            <td><?= e($user['full_name']) ?></td>

                            <td><?= e($user['email']) ?></td>

                            <td><?= e($user['phone']) ?></td>

                            <td>
                                <span class="badge role">
                                    <?= e($roleLabels[$user['role']] ?? $user['role']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= e($user['status']) ?>">
                                    <?= $user['status'] === 'active' ? 'نشط' : 'معطل' ?>
                                </span>
                            </td>

                            <td><?= e($user['created_at']) ?></td>

                            <td>
                                <div class="form-actions">
                                    <a class="edit-btn"
                                       href="admin_users.php?edit=<?= (int)$user['id'] ?>#userForm">
                                        تعديل
                                    </a>

                                    <?php if ((int)$user['id'] !== $userId): ?>
                                        <form method="POST"
                                            onsubmit="return confirm('هل أنت متأكد من تغيير حالة الحساب؟');">

                                            <input type="hidden" name="csrf_token"
                                                value="<?= e($csrfToken) ?>">

                                            <input type="hidden" name="action"
                                                value="toggle_status">

                                            <input type="hidden" name="user_id"
                                                value="<?= (int)$user['id'] ?>">

                                            <input type="hidden" name="new_status"
                                                value="<?= $user['status'] === 'active' ? 'inactive' : 'active' ?>">

                                            <button type="submit"
                                                class="status-btn <?= $user['status'] === 'active' ? 'disable-btn' : 'enable-btn' ?>">
                                                <?= $user['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty">لا توجد نتائج مطابقة.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

<script>
function showRoleFields() {
    const role = document.getElementById('role')?.value;

    document.querySelectorAll('.role-field').forEach(field => {
        field.style.display = 'none';
    });

    const studentFields = [
        'studentFields',
        'studentDepartment',
        'studentLevel'
    ];

    const facultyFields = [
        'facultyFields',
        'facultyDepartment',
        'facultyTitle'
    ];

    if (role === 'student') {
        studentFields.forEach(id => {
            document.getElementById(id).style.display = 'block';
        });
    }

    if (role === 'faculty') {
        facultyFields.forEach(id => {
            document.getElementById(id).style.display = 'block';
        });
    }
}
</script>

</body>
</html>
