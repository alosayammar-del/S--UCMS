<?php
session_start();
require_once __DIR__ . '/config/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// السماح للطلاب فقط
if (($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('غير مصرح لك بالوصول إلى هذه الصفحة.');
}

$userId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'الطالب';

// إنشاء رمز حماية للنموذج
if (empty($_SESSION['request_csrf'])) {
    $_SESSION['request_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// أنواع الطلبات المتاحة
$requestTypes = [
    'إفادة طالب',
    'إخلاء طرف',
    'تأجيل فصل دراسي',
    'اعتذار عن مقرر',
    'طلب إعادة قيد',
    'طلب وثيقة تخرج',
    'طلب آخر'
];

// معالجة تقديم طلب جديد
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['request_csrf'], $csrfToken)) {
        $error = 'تعذر التحقق من الطلب. يرجى تحديث الصفحة والمحاولة مجددا.';
    } else {

        $requestType = trim($_POST['request_type'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!in_array($requestType, $requestTypes, true)) {
            $error = 'يرجى اختيار نوع طلب صحيح.';
        } elseif ($description === '') {
            $error = 'يرجى كتابة تفاصيل الطلب.';
        } elseif (mb_strlen($description) > 3000) {
            $error = 'تفاصيل الطلب طويلة جدا. الحد الأقصى 3000 حرف.';
        } else {

            try {
                $trackingId = 'UCMS-' . strtoupper(bin2hex(random_bytes(8)));

                $sql = "
                    INSERT INTO university_requests
                    (tracking_id, student_id, request_type, description, status)
                    VALUES
                    (:tracking_id, :student_id, :request_type, :description, 'pending')
                ";

                $stmt = $pdo->prepare($sql);

                $stmt->execute([
                    'tracking_id' => $trackingId,
                    'student_id' => $userId,
                    'request_type' => $requestType,
                    'description' => $description
                ]);

                $_SESSION['request_csrf'] = bin2hex(random_bytes(32));

                header('Location: student_requests.php?submitted=1');
                exit;

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'حدث خطأ أثناء إرسال الطلب. يرجى المحاولة لاحقا.';
            }
        }
    }
}

// رسالة نجاح بعد إعادة التوجيه
if (isset($_GET['submitted'])) {
    $message = 'تم إرسال طلبك بنجاح، ويمكنك متابعة حالته من القائمة أدناه.';
}

// جلب طلبات الطالب فقط
try {
    $sql = "
        SELECT
            tracking_id,
            request_type,
            description,
            status,
            submitted_at,
            staff_comments
        FROM university_requests
        WHERE student_id = :student_id
        ORDER BY submitted_at DESC, id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['student_id' => $userId]);
    $requests = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $requests = [];
    $error = 'تعذر تحميل الطلبات حاليا. يرجى المحاولة لاحقا.';
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function requestStatusText($status) {
    $statuses = [
        'pending' => 'قيد المراجعة',
        'approved' => 'تمت الموافقة',
        'rejected' => 'مرفوض'
    ];

    return $statuses[$status] ?? $status;
}

function requestStatusClass($status) {
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

    <title>الطلبات الجامعية</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #243247;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .header, .card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        h1, h2 {
            color: #183b66;
        }

        h1 {
            margin-top: 0;
        }

        .welcome {
            color: #64748b;
        }

        .back {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 18px;
            background: #183b66;
            color: white;
            text-decoration: none;
            border-radius: 7px;
        }

        label {
            display: block;
            margin: 18px 0 8px;
            font-weight: bold;
        }

        select, textarea {
            width: 100%;
            padding: 13px;
            border: 1px solid #d5deea;
            border-radius: 8px;
            font-family: Arial, sans-serif;
            font-size: 15px;
            background: white;
        }

        textarea {
            min-height: 130px;
            resize: vertical;
        }

        button {
            margin-top: 18px;
            padding: 12px 25px;
            background: #183b66;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
        }

        button:hover {
            background: #24558d;
        }

        .success, .error {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .success {
            background: #e5f6eb;
            color: #16723b;
        }

        .error {
            background: #fdeaea;
            color: #b42318;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 750px;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px;
            text-align: right;
            border-bottom: 1px solid #e5eaf1;
            vertical-align: top;
        }

        th {
            background: #edf3fa;
            color: #183b66;
        }

        .status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 13px;
        }

        .pending {
            background: #fff3d6;
            color: #936300;
        }

        .approved {
            background: #e5f6eb;
            color: #16723b;
        }

        .rejected {
            background: #fdeaea;
            color: #b42318;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #64748b;
            background: #f8fafc;
            border-radius: 8px;
        }

        .tracking {
            font-weight: bold;
            color: #183b66;
            direction: ltr;
            display: inline-block;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .header, .card {
                padding: 16px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>الطلبات الجامعية</h1>

        <div class="welcome">
            مرحبا، <?= e($fullName) ?>
        </div>

        <a class="back" href="dashboard.php">
            العودة إلى لوحة التحكم
        </a>
    </div>

    <div class="card">

        <h2>تقديم طلب جامعي جديد</h2>

        <?php if ($message): ?>
            <div class="success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="student_requests.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['request_csrf']) ?>"
            >

            <label for="request_type">نوع الطلب</label>

            <select id="request_type" name="request_type" required>
                <option value="">اختر نوع الطلب</option>

                <?php foreach ($requestTypes as $type): ?>
                    <option value="<?= e($type) ?>">
                        <?= e($type) ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <label for="description">تفاصيل الطلب</label>

            <textarea
                id="description"
                name="description"
                maxlength="3000"
                placeholder="اكتب تفاصيل طلبك هنا..."
                required
            ></textarea>

            <button type="submit">إرسال الطلب</button>

        </form>

    </div>

    <div class="card">

        <h2>طلباتي السابقة</h2>

        <?php if (count($requests) > 0): ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                        <tr>
                            <th>رقم التتبع</th>
                            <th>نوع الطلب</th>
                            <th>التفاصيل</th>
                            <th>تاريخ التقديم</th>
                            <th>الحالة</th>
                            <th>ملاحظات الموظف</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($requests as $request): ?>

                            <tr>
                                <td>
                                    <span class="tracking">
                                        <?= e($request['tracking_id']) ?>
                                    </span>
                                </td>

                                <td><?= e($request['request_type']) ?></td>

                                <td><?= nl2br(e($request['description'])) ?></td>

                                <td><?= e($request['submitted_at']) ?></td>

                                <td>
                                    <span class="status <?= e(requestStatusClass($request['status'])) ?>">
                                        <?= e(requestStatusText($request['status'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e($request['staff_comments'] ?: 'لا توجد ملاحظات') ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php else: ?>

            <div class="empty">
                لا توجد طلبات جامعية سابقة.
                <br><br>
                يمكنك تقديم طلب جديد من النموذج أعلاه.
            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
