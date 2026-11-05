<?php
session_start();

require_once __DIR__ . '/config/database.php';

/* التحقق من صلاحية مدير النظام */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: index.php');
    exit;
}

$success = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/* معالجة العمليات */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
            throw new Exception('طلب غير صالح. يرجى تحديث الصفحة.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $password = $_POST['password'] ?? '';
            $employeeNumber = trim($_POST['employee_number'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $academicTitle = trim($_POST['academic_title'] ?? '');
            $status = $_POST['status'] ?? 'active';

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('يرجى إدخال الاسم والبريد الإلكتروني بشكل صحيح.');
            }

            if (strlen($password) < 8) {
                throw new Exception('كلمة المرور يجب ألا تقل عن 8 أحرف.');
            }

            if ($employeeNumber === '' || $department === '' || $academicTitle === '') {
                throw new Exception('يرجى إكمال جميع البيانات الوظيفية والأكاديمية.');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('حالة الحساب غير صحيحة.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO users
                (full_name, email, phone, password_hash, role, status)
                VALUES (?, ?, ?, ?, 'faculty', ?)
            ");

            $stmt->execute([
                $name,
                $email,
                $phone,
                password_hash($password, PASSWORD_DEFAULT),
                $status
            ]);

            $newUserId = (int)$pdo->lastInsertId();

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

            $pdo->commit();
            $success = 'تمت إضافة عضو هيئة التدريس بنجاح.';
        }

        elseif ($action === 'edit') {
            $facultyId = (int)($_POST['faculty_id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $employeeNumber = trim($_POST['employee_number'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $academicTitle = trim($_POST['academic_title'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($facultyId <= 0 || $name === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('يرجى التحقق من البيانات المدخلة.');
            }

            if ($employeeNumber === '' || $department === '' || $academicTitle === '') {
                throw new Exception('يرجى إكمال جميع البيانات الوظيفية والأكاديمية.');
            }

            if ($password !== '' && strlen($password) < 8) {
                throw new Exception('كلمة المرور يجب ألا تقل عن 8 أحرف.');
            }

            $pdo->beginTransaction();

            if ($password !== '') {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, phone = ?, password_hash = ?
                    WHERE id = ? AND role = 'faculty'
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    password_hash($password, PASSWORD_DEFAULT),
                    $facultyId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, phone = ?
                    WHERE id = ? AND role = 'faculty'
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $facultyId
                ]);
            }

            $stmt = $pdo->prepare("
                UPDATE faculty_members
                SET employee_number = ?, department = ?, academic_title = ?
                WHERE user_id = ?
            ");

            $stmt->execute([
                $employeeNumber,
                $department,
                $academicTitle,
                $facultyId
            ]);

            $pdo->commit();
            $success = 'تم تحديث بيانات عضو هيئة التدريس بنجاح.';
        }

        elseif ($action === 'toggle_status') {
            $facultyId = (int)($_POST['faculty_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';

            if ($facultyId <= 0 ||
                !in_array($newStatus, ['active', 'inactive'], true)) {
                throw new Exception('بيانات العملية غير صحيحة.');
            }

            if ($facultyId === (int)$_SESSION['user_id'] && $newStatus === 'inactive') {
                throw new Exception('لا يمكنك تعطيل حسابك الحالي.');
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET status = ?
                WHERE id = ? AND role = 'faculty'
            ");

            $stmt->execute([$newStatus, $facultyId]);

            $success = 'تم تحديث حالة الحساب بنجاح.';
        }

        else {
            throw new Exception('العملية المطلوبة غير معروفة.');
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e->getCode() === '23000') {
            $error = 'البريد الإلكتروني أو الرقم الوظيفي مستخدم مسبقا.';
        } else {
            $error = 'حدث خطأ في قاعدة البيانات. تحقق من البيانات وحاول مرة أخرى.';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}

/* البحث والتصفية */
$search = trim($_GET['search'] ?? '');
$departmentFilter = trim($_GET['department'] ?? '');
$titleFilter = trim($_GET['title'] ?? '');
$statusFilter = $_GET['status'] ?? '';

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        u.status,
        u.created_at,
        f.employee_number,
        f.department,
        f.academic_title
    FROM faculty_members f
    INNER JOIN users u ON u.id = f.user_id
    WHERE u.role = 'faculty'
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR f.employee_number LIKE ?
        )
    ";

    $keyword = "%{$search}%";
    array_push($params, $keyword, $keyword, $keyword, $keyword);
}

if ($departmentFilter !== '') {
    $sql .= " AND f.department = ?";
    $params[] = $departmentFilter;
}

if ($titleFilter !== '') {
    $sql .= " AND f.academic_title = ?";
    $params[] = $titleFilter;
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $sql .= " AND u.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY u.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$facultyMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* الإحصائيات */
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN u.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
        COUNT(DISTINCT f.department) AS departments_count
    FROM faculty_members f
    INNER JOIN users u ON u.id = f.user_id
    WHERE u.role = 'faculty'
");

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/* خيارات الأقسام والألقاب */
$departments = $pdo->query("
    SELECT DISTINCT department
    FROM faculty_members
    WHERE department IS NOT NULL AND department <> ''
    ORDER BY department
")->fetchAll(PDO::FETCH_COLUMN);

$academicTitles = $pdo->query("
    SELECT DISTINCT academic_title
    FROM faculty_members
    WHERE academic_title IS NOT NULL AND academic_title <> ''
    ORDER BY academic_title
")->fetchAll(PDO::FETCH_COLUMN);

/* بيانات العضو المطلوب تعديله */
$editFaculty = null;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $editStmt = $pdo->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            u.status,
            f.employee_number,
            f.department,
            f.academic_title
        FROM faculty_members f
        INNER JOIN users u ON u.id = f.user_id
        WHERE u.id = ? AND u.role = 'faculty'
        LIMIT 1
    ");

    $editStmt->execute([$editId]);
    $editFaculty = $editStmt->fetch(PDO::FETCH_ASSOC);
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>إدارة أعضاء هيئة التدريس | S-UCMS</title>

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
            flex-wrap: wrap;
            gap: 15px;
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
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 18px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
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

        @media(max-width: 750px) {
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
            <h1>إدارة أعضاء هيئة التدريس</h1>
            <p>إدارة بيانات أعضاء هيئة التدريس وحساباتهم الوظيفية والأكاديمية.</p>
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
            <span>إجمالي أعضاء هيئة التدريس</span>
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
            <span>الأقسام الأكاديمية</span>
            <h2><?= (int)$stats['departments_count'] ?></h2>
        </div>

    </div>

    <div class="panel" id="facultyForm">

        <h2><?= $editFaculty ? 'تعديل بيانات عضو هيئة التدريس' : 'إضافة عضو هيئة تدريس جديد' ?></h2>

        <form method="POST">

            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <?php if ($editFaculty): ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="faculty_id" value="<?= (int)$editFaculty['id'] ?>">
            <?php else: ?>
                <input type="hidden" name="action" value="add">
            <?php endif; ?>

            <div class="form-grid">

                <div class="field">
                    <label>الاسم الكامل</label>
                    <input type="text" name="full_name" required
                        value="<?= e($editFaculty['full_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>البريد الإلكتروني</label>
                    <input type="email" name="email" required
                        value="<?= e($editFaculty['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>رقم الهاتف</label>
                    <input type="text" name="phone"
                        value="<?= e($editFaculty['phone'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>الرقم الوظيفي</label>
                    <input type="text" name="employee_number" required
                        value="<?= e($editFaculty['employee_number'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>القسم الأكاديمي</label>
                    <input type="text" name="department" required
                        value="<?= e($editFaculty['department'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>اللقب الأكاديمي</label>
                    <input type="text" name="academic_title" required
                        value="<?= e($editFaculty['academic_title'] ?? '') ?>">
                </div>

                <?php if (!$editFaculty): ?>
                    <div class="field">
                        <label>كلمة المرور</label>
                        <input type="password" name="password" required minlength="8"
                            placeholder="8 أحرف على الأقل">
                    </div>

                    <div class="field">
                        <label>حالة الحساب</label>
                        <select name="status">
                            <option value="active">نشط</option>
                            <option value="inactive">معطل</option>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label>كلمة مرور جديدة (اختياري)</label>
                        <input type="password" name="password" minlength="8"
                            placeholder="اتركها فارغة دون تغيير">
                    </div>
                <?php endif; ?>

            </div>

            <div class="actions">
                <button type="submit" class="primary">
                    <?= $editFaculty ? 'حفظ التعديلات' : 'إضافة عضو هيئة التدريس' ?>
                </button>

                <?php if ($editFaculty): ?>
                    <a href="admin_faculty.php" class="cancel secondary">إلغاء التعديل</a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <div class="panel">

        <h2>قائمة أعضاء هيئة التدريس</h2>

        <form method="GET" class="filters">

            <input type="text" name="search"
                placeholder="ابحث بالاسم أو البريد أو الرقم الوظيفي..."
                value="<?= e($search) ?>">

            <select name="department">
                <option value="">جميع الأقسام</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= e($department) ?>"
                        <?= $departmentFilter === $department ? 'selected' : '' ?>>
                        <?= e($department) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="title">
                <option value="">جميع الألقاب الأكاديمية</option>
                <?php foreach ($academicTitles as $title): ?>
                    <option value="<?= e($title) ?>"
                        <?= $titleFilter === $title ? 'selected' : '' ?>>
                        <?= e($title) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">جميع الحالات</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>
                    نشط
                </option>
                <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>
                    معطل
                </option>
            </select>

            <button type="submit" class="primary">بحث</button>

        </form>

        <div class="table-wrapper">

            <table>
                <thead>
                    <tr>
                        <th>الرقم</th>
                        <th>الرقم الوظيفي</th>
                        <th>الاسم</th>
                        <th>البريد الإلكتروني</th>
                        <th>رقم الهاتف</th>
                        <th>القسم</th>
                        <th>اللقب الأكاديمي</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($facultyMembers) > 0): ?>

                    <?php foreach ($facultyMembers as $faculty): ?>

                        <tr>
                            <td><?= (int)$faculty['id'] ?></td>

                            <td><?= e($faculty['employee_number']) ?></td>

                            <td><?= e($faculty['full_name']) ?></td>

                            <td><?= e($faculty['email']) ?></td>

                            <td><?= e($faculty['phone']) ?></td>

                            <td><?= e($faculty['department']) ?></td>

                            <td><?= e($faculty['academic_title']) ?></td>

                            <td>
                                <span class="badge <?= e($faculty['status']) ?>">
                                    <?= $faculty['status'] === 'active' ? 'نشط' : 'معطل' ?>
                                </span>
                            </td>

                            <td>
                                <div class="form-actions">

                                    <a class="edit-btn"
                                       href="admin_faculty.php?edit=<?= (int)$faculty['id'] ?>#facultyForm">
                                        تعديل
                                    </a>

                                    <form method="POST"
                                        onsubmit="return confirm('هل تريد تغيير حالة حساب عضو هيئة التدريس؟');">

                                        <input type="hidden" name="csrf_token"
                                            value="<?= e($csrfToken) ?>">

                                        <input type="hidden" name="action"
                                            value="toggle_status">

                                        <input type="hidden" name="faculty_id"
                                            value="<?= (int)$faculty['id'] ?>">

                                        <input type="hidden" name="new_status"
                                            value="<?= $faculty['status'] === 'active' ? 'inactive' : 'active' ?>">

                                        <button type="submit"
                                            class="status-btn <?= $faculty['status'] === 'active' ? 'disable-btn' : 'enable-btn' ?>">
                                            <?= $faculty['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>
                                        </button>

                                    </form>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="9" class="empty">
                            لا توجد بيانات لأعضاء هيئة التدريس.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

</div>

</body>
</html>
