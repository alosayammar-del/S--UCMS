<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: index.php');
    exit;
}

$pdo = $pdo ?? $conn ?? null;

if (!$pdo instanceof PDO) {
    die('تعذر الاتصال بقاعدة البيانات.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$successMessage = '';
$errorMessage = '';

$statusLabels = [
    'pending' => 'قيد الانتظار',
    'in_progress' => 'قيد المعالجة',
    'approved' => 'تمت الموافقة',
    'rejected' => 'مرفوض',
    'completed' => 'مكتمل'
];

$statusClasses = [
    'pending' => 'pending',
    'in_progress' => 'progress',
    'approved' => 'approved',
    'rejected' => 'rejected',
    'completed' => 'completed'
];

$requestTypes = [
    'certificate' => 'طلب شهادة',
    'transcript' => 'كشف درجات',
    'registration' => 'طلب تسجيل',
    'withdrawal' => 'طلب انسحاب',
    'complaint' => 'شكوى',
    'other' => 'طلب آخر'
];

// تحديث حالة الطلب وملاحظات المسؤول
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errorMessage = 'تعذر التحقق من الطلب. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $comments = trim($_POST['staff_comments'] ?? '');

        if ($requestId <= 0) {
            $errorMessage = 'رقم الطلب غير صحيح.';
        } elseif (!array_key_exists($newStatus, $statusLabels)) {
            $errorMessage = 'حالة الطلب المحددة غير صحيحة.';
        } else {
            try {
                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM university_requests
                    WHERE id = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$requestId]);

                if (!$checkStmt->fetchColumn()) {
                    $errorMessage = 'الطلب غير موجود.';
                } else {
                    $updateStmt = $pdo->prepare("
                        UPDATE university_requests
                        SET status = ?,
                            staff_comments = ?,
                            processed_by = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");

                    $updateStmt->execute([
                        $newStatus,
                        $comments !== '' ? $comments : null,
                        (int)$_SESSION['user_id'],
                        $requestId
                    ]);

                    $successMessage = 'تم تحديث الطلب بنجاح.';
                }
            } catch (PDOException $e) {
                $errorMessage = 'حدث خطأ أثناء تحديث الطلب: ' . $e->getMessage();
            }
        }
    }
}

// البحث والتصفية
$search = trim($_GET['search'] ?? '');
$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';

try {
    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
        FROM university_requests
    ")->fetch(PDO::FETCH_ASSOC);

    $sql = "
        SELECT
            r.id,
            r.tracking_id,
            r.student_id,
            r.request_type,
            r.description,
            r.status,
            r.submitted_at,
            r.staff_comments,
            r.updated_at,
            u.full_name,
            u.email,
            st.student_number,
            processor.full_name AS processor_name
        FROM university_requests r
        LEFT JOIN students st ON st.user_id = r.student_id
        LEFT JOIN users u ON u.id = r.student_id
        LEFT JOIN users processor ON processor.id = r.processed_by
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                r.tracking_id LIKE ?
                OR u.full_name LIKE ?
                OR u.email LIKE ?
                OR st.student_number LIKE ?
                OR r.description LIKE ?
            )
        ";

        $searchValue = '%' . $search . '%';

        $params = array_merge($params, [
            $searchValue,
            $searchValue,
            $searchValue,
            $searchValue,
            $searchValue
        ]);
    }

    if ($filterStatus !== '' && array_key_exists($filterStatus, $statusLabels)) {
        $sql .= " AND r.status = ?";
        $params[] = $filterStatus;
    }

    if ($filterType !== '') {
        $sql .= " AND r.request_type = ?";
        $params[] = $filterType;
    }

    $sql .= " ORDER BY r.submitted_at DESC, r.id DESC LIMIT 300";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('حدث خطأ أثناء تحميل الطلبات: ' . htmlspecialchars($e->getMessage()));
}

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الطلبات الجامعية | S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Tahoma, sans-serif;
            background: #f4f6fb;
            color: #263247;
        }

        .container {
            width: 94%;
            max-width: 1450px;
            margin: 30px auto;
        }

        .header,
        .panel {
            background: #fff;
            border-radius: 13px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        h1 {
            color: #193b69;
            font-size: 25px;
            margin: 0 0 10px;
        }

        h2 {
            color: #193b69;
            font-size: 19px;
            margin: 0 0 18px;
        }

        .subtitle {
            color: #718096;
            margin: 0 0 18px;
        }

        .back-link {
            display: inline-block;
            background: #244d7c;
            color: #fff;
            text-decoration: none;
            padding: 10px 17px;
            border-radius: 7px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }

        .stat-card {
            background: #fff;
            padding: 19px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .stat-card span {
            display: block;
            color: #718096;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .stat-card strong {
            color: #244d7c;
            font-size: 27px;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            align-items: end;
        }

        label {
            display: block;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 8px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #dce2eb;
            border-radius: 7px;
            font-family: inherit;
            font-size: 14px;
            background: #fff;
        }

        textarea {
            min-height: 75px;
            resize: vertical;
        }

        button,
        .reset {
            border: none;
            border-radius: 7px;
            padding: 11px 17px;
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .primary {
            background: #244d7c;
            color: #fff;
        }

        .reset {
            background: #e8edf4;
            color: #334155;
            text-align: center;
        }

        .message {
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .success-message {
            background: #e3f6ec;
            color: #167044;
        }

        .error-message {
            background: #feeceb;
            color: #b42318;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1050px;
            border-collapse: collapse;
        }

        th,
        td {
            text-align: right;
            padding: 13px 11px;
            border-bottom: 1px solid #edf0f5;
            vertical-align: top;
            font-size: 13px;
        }

        th {
            background: #f5f7fb;
            color: #40516b;
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            white-space: nowrap;
        }

        .pending {
            color: #946200;
            background: #fff3cd;
        }

        .progress {
            color: #176b87;
            background: #e3f5fa;
        }

        .approved,
        .completed {
            color: #167044;
            background: #e3f6ec;
        }

        .rejected {
            color: #b42318;
            background: #feeceb;
        }

        .request-description {
            min-width: 180px;
            max-width: 260px;
            white-space: normal;
            line-height: 1.7;
        }

        .update-form {
            min-width: 220px;
        }

        .update-form select,
        .update-form textarea {
            margin-bottom: 9px;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #718096;
        }

        @media (max-width: 600px) {
            .container {
                width: 96%;
                margin: 15px auto;
            }

            .header,
            .panel {
                padding: 17px;
            }

            h1 {
                font-size: 21px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>إدارة الطلبات الجامعية</h1>
        <p class="subtitle">
            متابعة طلبات الطلاب ومعالجة المعاملات الجامعية وتحديث حالتها.
        </p>
        <a class="back-link" href="dashboard.php">العودة إلى لوحة التحكم</a>
    </div>

    <?php if ($successMessage): ?>
        <div class="message success-message"><?= h($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="message error-message"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="stats">

        <div class="stat-card">
            <span>إجمالي الطلبات</span>
            <strong><?= (int)($stats['total'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>قيد الانتظار</span>
            <strong><?= (int)($stats['pending_count'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>قيد المعالجة</span>
            <strong><?= (int)($stats['progress_count'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>تمت الموافقة</span>
            <strong><?= (int)($stats['approved_count'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>مرفوضة</span>
            <strong><?= (int)($stats['rejected_count'] ?? 0) ?></strong>
        </div>

        <div class="stat-card">
            <span>مكتملة</span>
            <strong><?= (int)($stats['completed_count'] ?? 0) ?></strong>
        </div>

    </div>

    <div class="panel">
        <h2>البحث والتصفية</h2>

        <form method="GET" action="admin_requests.php" class="filters">

            <div>
                <label>البحث</label>
                <input
                    type="text"
                    name="search"
                    value="<?= h($search) ?>"
                    placeholder="رقم التتبع أو اسم الطالب أو الرقم الجامعي"
                >
            </div>

            <div>
                <label>حالة الطلب</label>
                <select name="status">
                    <option value="">جميع الحالات</option>
                    <?php foreach ($statusLabels as $value => $label): ?>
                        <option value="<?= h($value) ?>"
                            <?= $filterStatus === $value ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>نوع الطلب</label>
                <select name="type">
                    <option value="">جميع الأنواع</option>
                    <?php foreach ($requestTypes as $value => $label): ?>
                        <option value="<?= h($value) ?>"
                            <?= $filterType === $value ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="primary">تطبيق التصفية</button>

            <a href="admin_requests.php" class="reset">إعادة تعيين</a>

        </form>
    </div>

    <div class="panel">
        <h2>قائمة الطلبات الجامعية</h2>

        <div class="table-wrapper">
            <table>

                <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>رقم التتبع</th>
                    <th>الطالب</th>
                    <th>الرقم الجامعي</th>
                    <th>نوع الطلب</th>
                    <th>تفاصيل الطلب</th>
                    <th>تاريخ التقديم</th>
                    <th>الحالة</th>
                    <th>آخر معالجة بواسطة</th>
                    <th>تحديث الطلب</th>
                </tr>
                </thead>

                <tbody>

                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="10" class="empty">
                            لا توجد طلبات جامعية مطابقة لخيارات البحث.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($requests as $request): ?>

                        <tr>
                            <td><?= (int)$request['id'] ?></td>

                            <td><?= h($request['tracking_id']) ?></td>

                            <td>
                                <?= h($request['full_name'] ?? 'طالب غير معروف') ?>
                                <br>
                                <small><?= h($request['email']) ?></small>
                            </td>

                            <td><?= h($request['student_number'] ?? '—') ?></td>

                            <td>
                                <?= h($requestTypes[$request['request_type']] ?? $request['request_type']) ?>
                            </td>

                            <td class="request-description">
                                <?= nl2br(h($request['description'])) ?>
                            </td>

                            <td><?= h($request['submitted_at']) ?></td>

                            <td>
                                <span class="badge <?= h($statusClasses[$request['status']] ?? 'pending') ?>">
                                    <?= h($statusLabels[$request['status']] ?? $request['status']) ?>
                                </span>
                            </td>

                            <td><?= h($request['processor_name'] ?? '—') ?></td>

                            <td>
                                <form method="POST" action="admin_requests.php" class="update-form">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="request_id"
                                        value="<?= (int)$request['id'] ?>"
                                    >

                                    <label>تحديث الحالة</label>

                                    <select name="status" required>
                                        <?php foreach ($statusLabels as $value => $label): ?>
                                            <option value="<?= h($value) ?>"
                                                <?= $request['status'] === $value ? 'selected' : '' ?>>
                                                <?= h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <label>ملاحظات المسؤول</label>

                                    <textarea
                                        name="staff_comments"
                                        placeholder="اكتب ملاحظات المعالجة..."
                                    ><?= h($request['staff_comments']) ?></textarea>

                                    <button type="submit" class="primary">
                                        حفظ التحديث
                                    </button>

                                </form>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>
        </div>
    </div>

</div>

</body>
</html>
