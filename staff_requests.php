<?php
session_start();

require_once __DIR__ . '/config/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// جلب بيانات المستخدم
$userStmt = $pdo->prepare("
    SELECT id, full_name, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

// التحقق من وجود المستخدم وصلاحياته
if (!$user || $user['role'] !== 'staff' || $user['status'] !== 'active') {
    header('Location: index.php');
    exit;
}

$fullName = $user['full_name'];

$message = '';
$messageType = '';

// معالجة تحديث الطلب
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';
    $staffComments = trim($_POST['staff_comments'] ?? '');

    $allowedStatuses = ['pending', 'approved', 'rejected'];

    if (!$requestId || !in_array($status, $allowedStatuses, true)) {
        $message = 'بيانات الطلب غير صحيحة.';
        $messageType = 'error';
    } else {
        try {
            $checkStmt = $pdo->prepare("
                SELECT id
                FROM university_requests
                WHERE id = ?
                LIMIT 1
            ");
            $checkStmt->execute([$requestId]);

            if (!$checkStmt->fetch()) {
                $message = 'الطلب غير موجود.';
                $messageType = 'error';
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
                    $status,
                    $staffComments !== '' ? $staffComments : null,
                    $userId,
                    $requestId
                ]);

                $message = 'تم تحديث حالة الطلب بنجاح.';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'حدث خطأ أثناء تحديث الطلب.';
            $messageType = 'error';
        }
    }
}

// جلب الطلبات الجامعية
$requestsStmt = $pdo->query("
    SELECT
        ur.id,
        ur.tracking_id,
        ur.student_id,
        ur.request_type,
        ur.description,
        ur.status,
        ur.submitted_at,
        ur.staff_comments,
        ur.updated_at,
        u.full_name AS student_name,
        u.email AS student_email
    FROM university_requests ur
    LEFT JOIN users u
        ON u.id = ur.student_id
    ORDER BY ur.submitted_at DESC
");

$requests = $requestsStmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات الطلبات
$totalRequests = count($requests);
$pendingRequests = 0;
$approvedRequests = 0;
$rejectedRequests = 0;

foreach ($requests as $request) {
    if ($request['status'] === 'pending') {
        $pendingRequests++;
    } elseif ($request['status'] === 'approved') {
        $approvedRequests++;
    } elseif ($request['status'] === 'rejected') {
        $rejectedRequests++;
    }
}

// تحويل الحالة إلى العربية
function getRequestStatus($status)
{
    $statuses = [
        'pending' => 'قيد المراجعة',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوض'
    ];

    return $statuses[$status] ?? $status;
}

function getStatusClass($status)
{
    $classes = [
        'pending' => 'pending',
        'approved' => 'approved',
        'rejected' => 'rejected'
    ];

    return $classes[$status] ?? '';
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الطلبات الجامعية - S-UCMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f7fb;
            color: #263445;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .header {
            background: linear-gradient(135deg, #173b68, #2463a6);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
        }

        .header h2 {
            margin: 0 0 10px;
        }

        .header p {
            margin: 0;
            opacity: .9;
        }

        .back-link {
            display: inline-block;
            margin-top: 18px;
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: 10px 16px;
            border-radius: 8px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .stat-card h3 {
            margin: 0 0 12px;
            font-size: 15px;
            color: #64748b;
        }

        .stat-card strong {
            font-size: 27px;
            color: #173b68;
        }

        .section {
            background: white;
            padding: 22px;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
            margin-bottom: 20px;
        }

        .section h3 {
            margin-top: 0;
            color: #173b68;
        }

        .alert {
            padding: 14px;
            border-radius: 9px;
            margin-bottom: 20px;
        }

        .alert.success {
            background: #e8f7ed;
            color: #18753b;
        }

        .alert.error {
            background: #fdecec;
            color: #b42318;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 12px;
            text-align: right;
            border-bottom: 1px solid #e8edf3;
            vertical-align: top;
        }

        th {
            background: #f2f6fb;
            color: #173b68;
            white-space: nowrap;
        }

        td {
            font-size: 14px;
        }

        .status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            white-space: nowrap;
        }

        .status.pending {
            background: #fff4d6;
            color: #946200;
        }

        .status.approved {
            background: #e5f7eb;
            color: #18753b;
        }

        .status.rejected {
            background: #fde8e8;
            color: #b42318;
        }

        .description {
            max-width: 200px;
            white-space: normal;
            line-height: 1.7;
        }

        .comments {
            max-width: 200px;
            white-space: normal;
            color: #64748b;
        }

        .edit-form {
            min-width: 190px;
        }

        select, textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 9px;
            border: 1px solid #d6deea;
            border-radius: 7px;
            font-family: inherit;
            font-size: 13px;
            background: white;
        }

        textarea {
            resize: vertical;
            min-height: 65px;
        }

        button {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 7px;
            background: #2463a6;
            color: white;
            font-family: inherit;
            cursor: pointer;
        }

        button:hover {
            background: #173b68;
        }

        .empty {
            text-align: center;
            color: #64748b;
            padding: 30px;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .header {
                padding: 20px;
            }

            .section {
                padding: 15px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h2>إدارة الطلبات الجامعية</h2>
        <p>مرحبا، <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></p>

        <a class="back-link" href="dashboard.php">
            العودة إلى لوحة التحكم
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert <?= htmlspecialchars($messageType) ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="stats">

        <div class="stat-card">
            <h3>إجمالي الطلبات</h3>
            <strong><?= $totalRequests ?></strong>
        </div>

        <div class="stat-card">
            <h3>قيد المراجعة</h3>
            <strong><?= $pendingRequests ?></strong>
        </div>

        <div class="stat-card">
            <h3>تمت الموافقة</h3>
            <strong><?= $approvedRequests ?></strong>
        </div>

        <div class="stat-card">
            <h3>الطلبات المرفوضة</h3>
            <strong><?= $rejectedRequests ?></strong>
        </div>

    </div>

    <div class="section">

        <h3>قائمة الطلبات المقدمة من الطلاب</h3>

        <?php if (empty($requests)): ?>

            <div class="empty">
                لا توجد طلبات جامعية حاليا.
            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                        <tr>
                            <th>رقم التتبع</th>
                            <th>اسم الطالب</th>
                            <th>نوع الطلب</th>
                            <th>وصف الطلب</th>
                            <th>تاريخ التقديم</th>
                            <th>الحالة</th>
                            <th>ملاحظات الموظف</th>
                            <th>معالجة الطلب</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($requests as $request): ?>

                        <tr>

                            <td>
                                <?= htmlspecialchars($request['tracking_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($request['student_name'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8') ?>
                                <br>
                                <small>
                                    <?= htmlspecialchars($request['student_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </small>
                            </td>

                            <td>
                                <?= htmlspecialchars($request['request_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td class="description">
                                <?= htmlspecialchars($request['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <?= htmlspecialchars($request['submitted_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>
                                <span class="status <?= htmlspecialchars(getStatusClass($request['status'])) ?>">
                                    <?= htmlspecialchars(getRequestStatus($request['status']), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>

                            <td class="comments">
                                <?= htmlspecialchars($request['staff_comments'] ?: 'لا توجد ملاحظات', ENT_QUOTES, 'UTF-8') ?>
                            </td>

                            <td>

                                <form method="POST" class="edit-form">

                                    <input
                                        type="hidden"
                                        name="request_id"
                                        value="<?= (int) $request['id'] ?>"
                                    >

                                    <select name="status" required>
                                        <option value="pending"
                                            <?= $request['status'] === 'pending' ? 'selected' : '' ?>>
                                            قيد المراجعة
                                        </option>

                                        <option value="approved"
                                            <?= $request['status'] === 'approved' ? 'selected' : '' ?>>
                                            الموافقة على الطلب
                                        </option>

                                        <option value="rejected"
                                            <?= $request['status'] === 'rejected' ? 'selected' : '' ?>>
                                            رفض الطلب
                                        </option>
                                    </select>

                                    <textarea
                                        name="staff_comments"
                                        placeholder="أدخل ملاحظات الموظف..."
                                    ><?= htmlspecialchars($request['staff_comments'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                                    <button type="submit">
                                        حفظ التحديث
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
