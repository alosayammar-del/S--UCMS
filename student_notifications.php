<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('غير مصرح لك بالوصول إلى هذه الصفحة');
}

$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT id, full_name, status
    FROM users
    WHERE id = ? AND role = 'student'
    LIMIT 1
");
$stmt->execute([$userId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student || $student['status'] !== 'active') {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'انتهت صلاحية الطلب. يرجى تحديث الصفحة والمحاولة مرة أخرى.';
    } else {
        $notificationId = filter_input(
            INPUT_POST,
            'notification_id',
            FILTER_VALIDATE_INT
        );

        if ($notificationId) {
            $update = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE id = ? AND user_id = ?
            ");
            $update->execute([$notificationId, $userId]);

            $success = 'تم تحديث حالة الإشعار بنجاح.';
        } else {
            $error = 'رقم الإشعار غير صحيح.';
        }
    }
}

$alertsQuery = $pdo->prepare("
    SELECT id, attendance_indicator, assignment_indicator,
           assessment_indicator, risk_level, details,
           created_at, status
    FROM academic_alerts
    WHERE student_id = ?
    ORDER BY created_at DESC
");
$alertsQuery->execute([$userId]);
$alerts = $alertsQuery->fetchAll(PDO::FETCH_ASSOC);

$notificationsQuery = $pdo->prepare("
    SELECT id, title, message, notification_type,
           is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$notificationsQuery->execute([$userId]);
$notifications = $notificationsQuery->fetchAll(PDO::FETCH_ASSOC);

$unreadCount = 0;
foreach ($notifications as $notification) {
    if (!(int) $notification['is_read']) {
        $unreadCount++;
    }
}

function riskLabel($level) {
    $labels = [
        'satisfactory' => 'مستقر',
        'needs_improvement' => 'يحتاج إلى تحسين',
        'critical_risk' => 'خطر حرج'
    ];

    return $labels[$level] ?? 'غير محدد';
}

function alertStatusLabel($status) {
    $labels = [
        'new' => 'جديد',
        'viewed' => 'تمت المشاهدة',
        'resolved' => 'تمت المعالجة'
    ];

    return $labels[$status] ?? 'غير محدد';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التنبيهات والإشعارات</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            font-family: Tahoma, Arial, sans-serif;
            background: #f4f6fa;
            color: #263247;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .header, .section, .message {
            background: #fff;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.05);
        }

        h1, h2 {
            margin-top: 0;
        }

        h1 {
            color: #173b68;
            font-size: 25px;
        }

        h2 {
            color: #173b68;
            font-size: 19px;
        }

        .welcome {
            color: #68758a;
            margin-bottom: 15px;
        }

        a {
            color: #1765ad;
            text-decoration: none;
        }

        .back {
            display: inline-block;
            padding: 10px 16px;
            background: #173b68;
            color: white;
            border-radius: 7px;
        }

        .count {
            display: inline-block;
            background: #e9f1fb;
            color: #175a9b;
            padding: 8px 13px;
            border-radius: 20px;
            margin-bottom: 15px;
        }

        .item {
            border: 1px solid #e5e9f0;
            border-right: 4px solid #3b82c4;
            padding: 16px;
            border-radius: 8px;
            margin-top: 13px;
            background: #fff;
        }

        .item.unread {
            background: #f3f8ff;
            border-right-color: #e29a25;
        }

        .item h3 {
            margin: 0 0 10px;
            color: #263f60;
            font-size: 17px;
        }

        .details {
            color: #566477;
            line-height: 1.8;
            margin: 8px 0;
        }

        .meta {
            color: #8993a2;
            font-size: 13px;
            margin-top: 10px;
        }

        .risk {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 13px;
            background: #edf1f6;
        }

        .risk.critical_risk {
            background: #fde8e8;
            color: #b42318;
        }

        .risk.needs_improvement {
            background: #fff3d9;
            color: #946200;
        }

        .risk.satisfactory {
            background: #e5f6eb;
            color: #16743a;
        }

        .btn {
            border: 0;
            padding: 8px 13px;
            background: #1765ad;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            margin-top: 10px;
        }

        .empty {
            color: #8993a2;
            padding: 15px 0;
        }

        .message {
            padding: 12px 18px;
            color: #16743a;
            background: #e8f6ed;
        }

        .error {
            color: #b42318;
            background: #fde8e8;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .header, .section {
                padding: 16px;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="header">
        <h1>التنبيهات والإشعارات</h1>

        <p class="welcome">
            مرحبا، <?= htmlspecialchars($student['full_name']) ?>
        </p>

        <a class="back" href="dashboard.php">
            العودة إلى لوحة التحكم
        </a>
    </div>

    <?php if ($success): ?>
        <div class="message">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="section">
        <h2>الإشعارات العامة</h2>

        <div class="count">
            الإشعارات غير المقروءة: <?= $unreadCount ?>
        </div>

        <?php if (count($notifications) > 0): ?>

            <?php foreach ($notifications as $notification): ?>
                <div class="item <?= !(int)$notification['is_read'] ? 'unread' : '' ?>">

                    <h3>
                        <?= htmlspecialchars($notification['title']) ?>
                    </h3>

                    <p class="details">
                        <?= nl2br(htmlspecialchars($notification['message'])) ?>
                    </p>

                    <div class="meta">
                        النوع:
                        <?= htmlspecialchars($notification['notification_type']) ?>
                        |
                        التاريخ:
                        <?= htmlspecialchars($notification['created_at']) ?>
                    </div>

                    <?php if (!(int)$notification['is_read']): ?>
                        <form method="POST">
                            <input type="hidden" name="csrf_token"
                                   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                            <input type="hidden" name="notification_id"
                                   value="<?= (int)$notification['id'] ?>">

                            <button class="btn" type="submit">
                                تحديد كمقروء
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="meta">تمت قراءة هذا الإشعار</p>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <p class="empty">لا توجد إشعارات عامة حاليا.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>التنبيهات الأكاديمية</h2>

        <?php if (count($alerts) > 0): ?>

            <?php foreach ($alerts as $alert): ?>
                <div class="item">

                    <h3>
                        مستوى التنبيه:
                        <?= htmlspecialchars(riskLabel($alert['risk_level'])) ?>
                    </h3>

                    <span class="risk <?= htmlspecialchars($alert['risk_level']) ?>">
                        <?= htmlspecialchars(riskLabel($alert['risk_level'])) ?>
                    </span>

                    <p class="details">
                        <?= nl2br(htmlspecialchars($alert['details'] ?? 'لا توجد تفاصيل إضافية.')) ?>
                    </p>

                    <p class="details">
                        مؤشر الحضور:
                        <?= htmlspecialchars($alert['attendance_indicator'] ?? 'غير متوفر') ?>%
                        <br>

                        مؤشر الواجبات:
                        <?= htmlspecialchars($alert['assignment_indicator'] ?? 'غير متوفر') ?>%
                        <br>

                        مؤشر التقييمات:
                        <?= htmlspecialchars($alert['assessment_indicator'] ?? 'غير متوفر') ?>%
                    </p>

                    <div class="meta">
                        الحالة:
                        <?= htmlspecialchars(alertStatusLabel($alert['status'])) ?>
                        |
                        التاريخ:
                        <?= htmlspecialchars($alert['created_at']) ?>
                    </div>

                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <p class="empty">
                لا توجد تنبيهات أكاديمية مسجلة حاليا.
            </p>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
