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
            $studentNumber = trim($_POST['student_number'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $level = trim($_POST['academic_level'] ?? '');
            $collegeType = $_POST['college_type'] ?? 'general';
            $status = $_POST['status'] ?? 'active';

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('يرجى إدخال الاسم والبريد الإلكتروني بشكل صحيح.');
            }

            if (strlen($password) < 8) {
                throw new Exception('كلمة المرور يجب ألا تقل عن 8 أحرف.');
            }

            if ($studentNumber === '' || $department === '' || $level === '') {
                throw new Exception('يرجى إكمال جميع البيانات الأكاديمية.');
            }

            if (!in_array($collegeType, ['general', 'health'], true)) {
                throw new Exception('نوع الكلية غير صحيح.');
            }

            if (!in_array($collegeType, ['general', 'health'], true)) {
                throw new Exception('نوع الكلية غير صحيح.');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('حالة الحساب غير صحيحة.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO users
                (full_name, email, phone, password_hash, role, status)
                VALUES (?, ?, ?, ?, 'student', ?)
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
                INSERT INTO students
                (user_id, student_number, department, academic_level, college_type)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $newUserId,
                $studentNumber,
                $department,
                $level,
                $collegeType
            ]);

            $pdo->commit();
            $success = 'تمت إضافة الطالب بنجاح وإنشاء حسابه.';
        }

        elseif ($action === 'edit') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $studentNumber = trim($_POST['student_number'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $level = trim($_POST['academic_level'] ?? '');
            $collegeType = $_POST['college_type'] ?? 'general';
            $password = $_POST['password'] ?? '';

            if ($studentId <= 0 || $name === '' ||
                !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('يرجى التحقق من البيانات المدخلة.');
            }

            if ($studentNumber === '' || $department === '' || $level === '') {
                throw new Exception('يرجى إكمال جميع البيانات الأكاديمية.');
            }

            if ($password !== '' && strlen($password) < 8) {
                throw new Exception('كلمة المرور يجب ألا تقل عن 8 أحرف.');
            }

            $pdo->beginTransaction();

            if ($password !== '') {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, phone = ?, password_hash = ?
                    WHERE id = ? AND role = 'student'
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    password_hash($password, PASSWORD_DEFAULT),
                    $studentId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, phone = ?
                    WHERE id = ? AND role = 'student'
                ");

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $studentId
                ]);
            }

            $stmt = $pdo->prepare("
                UPDATE students
                SET student_number = ?, department = ?, academic_level = ?, college_type = ?
                WHERE user_id = ?
            ");

            $stmt->execute([
                $studentNumber,
                $department,
                $level,
                $collegeType,
                $studentId
            ]);

            $pdo->commit();
            $success = 'تم تحديث بيانات الطالب بنجاح.';
        }

        elseif ($action === 'toggle_status') {
            $studentId = (int)($_POST['student_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? '';

            if ($studentId <= 0 ||
                !in_array($newStatus, ['active', 'inactive'], true)) {
                throw new Exception('بيانات العملية غير صحيحة.');
            }

            $stmt = $pdo->prepare("
                UPDATE users
                SET status = ?
                WHERE id = ? AND role = 'student'
            ");

            $stmt->execute([$newStatus, $studentId]);

            $success = 'تم تحديث حالة حساب الطالب.';
        }

        else {
            throw new Exception('العملية المطلوبة غير معروفة.');
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e->getCode() === '23000') {
            $error = 'البريد الإلكتروني أو الرقم الجامعي مستخدم مسبقا.';
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
$levelFilter = trim($_GET['level'] ?? '');
$collegeTypeFilter = $_GET['college_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$sql = "
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        u.status,
        u.created_at,
        s.student_number,
        s.department,
        s.academic_level,
        s.college_type
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    WHERE u.role = 'student'
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            u.full_name LIKE ?
            OR u.email LIKE ?
            OR u.phone LIKE ?
            OR s.student_number LIKE ?
        )
    ";

    $keyword = "%{$search}%";
    array_push($params, $keyword, $keyword, $keyword, $keyword);
}

if ($departmentFilter !== '') {
    $sql .= " AND s.department = ?";
    $params[] = $departmentFilter;
}

if ($levelFilter !== '') {
    $sql .= " AND s.academic_level = ?";
    $params[] = $levelFilter;
}

if (in_array($collegeTypeFilter, ['general', 'health'], true)) {
    $sql .= " AND s.college_type = ?";
    $params[] = $collegeTypeFilter;
}

if (in_array($statusFilter, ['active', 'inactive'], true)) {
    $sql .= " AND u.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY u.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* الإحصائيات */
$statsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN u.status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN u.status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count,
        COUNT(DISTINCT s.department) AS departments_count
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    WHERE u.role = 'student'
");

$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

/* خيارات الأقسام والمستويات */
$departments = $pdo->query("
    SELECT DISTINCT department
    FROM students
    WHERE department IS NOT NULL AND department <> ''
    ORDER BY department
")->fetchAll(PDO::FETCH_COLUMN);

$levels = $pdo->query("
    SELECT DISTINCT academic_level
    FROM students
    WHERE academic_level IS NOT NULL AND academic_level <> ''
    ORDER BY academic_level
")->fetchAll(PDO::FETCH_COLUMN);

/* بيانات الطالب المطلوب تعديله */
$editStudent = null;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    $editStmt = $pdo->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            u.status,
            s.student_number,
            s.department,
            s.academic_level,
            s.college_type
        FROM students s
        INNER JOIN users u ON u.id = s.user_id
        WHERE u.id = ? AND u.role = 'student'
        LIMIT 1
    ");

    $editStmt->execute([$editId]);
    $editStudent = $editStmt->fetch(PDO::FETCH_ASSOC);
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

    <title>إدارة الطلاب | S-UCMS</title>

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
            <h1>إدارة الطلاب</h1>
            <p>إدارة بيانات الطلاب الأكاديمية وحساباتهم الجامعية ومتابعة حالتهم.</p>
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
            <span>إجمالي الطلاب</span>
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

    <div class="panel" id="studentForm">

        <h2><?= $editStudent ? 'تعديل بيانات الطالب' : 'إضافة طالب جديد' ?></h2>

        <form method="POST">

            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <?php if ($editStudent): ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="student_id" value="<?= (int)$editStudent['id'] ?>">
            <?php else: ?>
                <input type="hidden" name="action" value="add">
            <?php endif; ?>

            <div class="form-grid">

                <div class="field">
                    <label>اسم الطالب</label>
                    <input type="text" name="full_name" required
                        value="<?= e($editStudent['full_name'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>البريد الإلكتروني</label>
                    <input type="email" name="email" required
                        value="<?= e($editStudent['email'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>رقم الهاتف</label>
                    <input type="text" name="phone"
                        value="<?= e($editStudent['phone'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>الرقم الجامعي</label>
                    <input type="text" name="student_number" required
                        value="<?= e($editStudent['student_number'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>القسم الأكاديمي</label>
                    <input type="text" name="department" required
                        value="<?= e($editStudent['department'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>المستوى الأكاديمي</label>
                    <input type="text" name="academic_level" required
                        value="<?= e($editStudent['academic_level'] ?? '') ?>">
                </div>

                <div class="field">
                    <label>نوع الكلية</label>
                    <select name="college_type" required>
                        <option value="general" <?= ($editStudent['college_type'] ?? 'general') === 'general' ? 'selected' : '' ?>>كلية عامة</option>
                        <option value="health" <?= ($editStudent['college_type'] ?? 'general') === 'health' ? 'selected' : '' ?>>كلية صحية</option>
                    </select>
                </div>

                <?php if (!$editStudent): ?>
                    <div class="field">
                        <label>كلمة المرور</label>
                        <input type="password" name="password" required minlength="8"
                            placeholder="8 أحرف على الأقل">
                    </div>
                <?php else: ?>
                    <div class="field">
                        <label>كلمة مرور جديدة (اختياري)</label>
                        <input type="password" name="password" minlength="8"
                            placeholder="اتركها فارغة دون تغيير">
                    </div>
                <?php endif; ?>

                <?php if (!$editStudent): ?>
                    <div class="field">
                        <label>حالة الحساب</label>
                        <select name="status">
                            <option value="active">نشط</option>
                            <option value="inactive">معطل</option>
                        </select>
                    </div>
                <?php endif; ?>

            </div>

            <div class="actions">
                <button type="submit" class="primary">
                    <?= $editStudent ? 'حفظ التعديلات' : 'إضافة الطالب' ?>
                </button>

                <?php if ($editStudent): ?>
                    <a href="admin_students.php" class="cancel secondary">إلغاء التعديل</a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <div class="panel">

        <h2>قائمة الطلاب</h2>

        <form method="GET" class="filters">

            <input type="text" name="search"
                placeholder="ابحث بالاسم أو البريد أو الرقم الجامعي..."
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

            <select name="level">
                <option value="">جميع المستويات</option>
                <?php foreach ($levels as $level): ?>
                    <option value="<?= e($level) ?>"
                        <?= $levelFilter === $level ? 'selected' : '' ?>>
                        <?= e($level) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="college_type">
                <option value="">جميع أنواع الكليات</option>
                <option value="general" <?= $collegeTypeFilter === 'general' ? 'selected' : '' ?>>كلية عامة</option>
                <option value="health" <?= $collegeTypeFilter === 'health' ? 'selected' : '' ?>>كلية صحية</option>
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
                        <th>الرقم الجامعي</th>
                        <th>اسم الطالب</th>
                        <th>البريد الإلكتروني</th>
                        <th>رقم الهاتف</th>
                        <th>القسم</th>
                        <th>المستوى</th>
                        <th>نوع الكلية</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($students) > 0): ?>

                    <?php foreach ($students as $student): ?>

                        <tr>
                            <td><?= (int)$student['id'] ?></td>

                            <td><?= e($student['student_number']) ?></td>

                            <td><?= e($student['full_name']) ?></td>

                            <td><?= e($student['email']) ?></td>

                            <td><?= e($student['phone']) ?></td>

                            <td><?= e($student['department']) ?></td>

                            <td><?= e($student['academic_level']) ?></td>

                            <td><?= ($student['college_type'] ?? 'general') === 'health' ? 'كلية صحية' : 'كلية عامة' ?></td>

                            <td>
                                <span class="badge <?= e($student['status']) ?>">
                                    <?= $student['status'] === 'active' ? 'نشط' : 'معطل' ?>
                                </span>
                            </td>

                            <td>
                                <div class="form-actions">

                                    <a class="edit-btn"
                                       href="admin_students.php?edit=<?= (int)$student['id'] ?>#studentForm">
                                        تعديل
                                    </a>

                                    <form method="POST"
                                        onsubmit="return confirm('هل تريد تغيير حالة حساب هذا الطالب؟');">

                                        <input type="hidden" name="csrf_token"
                                            value="<?= e($csrfToken) ?>">

                                        <input type="hidden" name="action"
                                            value="toggle_status">

                                        <input type="hidden" name="student_id"
                                            value="<?= (int)$student['id'] ?>">

                                        <input type="hidden" name="new_status"
                                            value="<?= $student['status'] === 'active' ? 'inactive' : 'active' ?>">

                                        <button type="submit"
                                            class="status-btn <?= $student['status'] === 'active' ? 'disable-btn' : 'enable-btn' ?>">
                                            <?= $student['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>
                                        </button>

                                    </form>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="10" class="empty">
                            لا توجد بيانات طلاب مطابقة.
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
