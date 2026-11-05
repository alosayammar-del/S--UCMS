<?php
session_start();

require_once __DIR__ . '/config/database.php';

// التحقق من تسجيل الدخول
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// جلب بيانات المستخدم والتحقق من الصلاحية
$userStmt = $pdo->prepare("
    SELECT id, full_name, role, status
    FROM users
    WHERE id = ?
    LIMIT 1
");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] !== 'staff' || $user['status'] !== 'active') {
    header('Location: index.php');
    exit;
}

$fullName = $user['full_name'];

$message = '';
$messageType = '';

// تحديد إشعار كمقروء
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $notificationId = filter_input(
        INPUT_POST,
        'notification_id',
        FILTER_VALIDATE_INT
    );

    if ($notificationId) {
        try {
            $updateStmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE id = ?
                  AND user_id = ?
            ");

            $updateStmt->execute([$notificationId, $userId]);

            if ($updateStmt->rowCount() > 0) {
                $message = 'تم تحديد الإشعار كمقروء.';
                $messageType = 'success';
            } else {
                $message = 'الإشعار غير موجود أو تمت قراءته مسبقا.';
                $messageType = 'error';
            }

        } catch (PDOException $e) {
            $message = 'حدث خطأ أثناء تحديث الإشعار.';
            $messageType = 'error';
        }
    }
}

// جلب إشعارات الموظف
$notificationsStmt = $pdo->prepare("
    SELECT
        id,
        title,
        message,
        notification_type,
        related_entity_type,
        related_entity_id,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");

$notificationsStmt->execute([$userId]);
$notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات الإشعارات
$totalNotifications = count($notifications);
$unreadNotifications = 0;
$readNotifications = 0;

foreach ($notifications as $notification) {
    if ((int) $notification['is_read'] === 1) {
        $readNotifications++;
    } else {
        $unreadNotifications++;
    }
}

// ترجمة نوع الإشعار
function getNotificationType($type)
{
    $types = [
        'info' => 'معلومات',
        'success' => 'نجاح',
        'warning' => 'تنبيه',
        'error' => 'خطأ',
        'announcement' => 'إعلان',
        'alert' => 'تنبيه'
    ];

    return $types[$type] ?? ($type ?: 'إشعار عام');
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>إشعارات الموظف - S-UCMS</title>

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
            max-width: 1100px;
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
        }

        .section h3 {
            margin-top: 0;
            color: #173b68;
            margin-bottom: 20px;
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

        .notification {
            border: 1px solid #e5eaf1;
            border-right: 4px solid #2463a6;
            padding: 18px;
            border-radius: 10px;
            margin-bottom: 15px;
            background: #fff;
        }

        .notification.unread {
            background: #f0f6ff;
            border-right-color: #2463a6;
        }

        .notification.read {
            border-right-color: #94a3b8;
        }

        .notification-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .notification-title {
            margin: 0;
            font-size: 17px;
            color: #173b68;
        }

        .notification-message {
            line-height: 1.9;
            color: #475569;
            margin: 10px 0;
            white-space: pre-wrap;
        }

        .notification-info {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            color: #64748b;
            font-size: 12px;
            margin-top: 12px;
        }

        .badge {
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 12px;
            white-space: nowrap;
        }

        .badge.unread {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge.read {
            background: #e2e8f0;
            color: #475569;
        }

        .badge.type {
            background: #f1f5f9;
            color: #475569;
        }

        .read-form {
            margin-top: 14px;
        }

        .read-button {
            border: none;
            background: #2463a6;
            color: white;
            padding: 9px 15px;
            border-radius: 7px;
            font-family: inherit;
            cursor: pointer;
        }

        .read-button:hover {
            background: #173b68;
        }

        .empty {
            text-align: center;
            padding: 35px 15px;
            color: #64748b;
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
        <h2>إشعارات الموظف</h2>

        <p>
            مرحبا، <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
        </p>

        <a href="dashboard.php" class="back-link">
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
            <h3>إجمالي الإشعارات</h3>
            <strong><?= $totalNotifications ?></strong>
        </div>

        <div class="stat-card">
            <h3>الإشعارات غير المقروءة</h3>
            <strong><?= $unreadNotifications ?></strong>
        </div>

        <div class="stat-card">
            <h3>الإشعارات المقروءة</h3>
            <strong><?= $readNotifications ?></strong>
        </div>

    </div>

    <div class="section">

        <h3>قائمة الإشعارات والتنبيهات</h3>

        <?php if (empty($notifications)): ?>

            <div class="empty">
                <h3>لا توجد إشعارات حاليا</h3>
                <p>ستظهر هنا الإشعارات والتنبيهات الخاصة بحسابك.</p>
            </div>

        <?php else: ?>

            <?php foreach ($notifications as $notification): ?>

                <?php
                    $isRead = (int) $notification['is_read'] === 1;
                ?>

                <div class="notification <?= $isRead ? 'read' : 'unread' ?>">

                    <div class="notification-top">

                        <h4 class="notification-title">
                            <?= htmlspecialchars($notification['title'] ?? 'إشعار بدون عنوان', ENT_QUOTES, 'UTF-8') ?>
                        </h4>

                        <span class="badge <?= $isRead ? 'read' : 'unread' ?>">
                            <?= $isRead ? 'مقروء' : 'غير مقروء' ?>
                        </span>

                    </div>

                    <span class="badge type">
                        <?= htmlspecialchars(getNotificationType($notification['notification_type']), ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <p class="notification-message">
                        <?= htmlspecialchars($notification['message'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <div class="notification-info">

                        <span>
                            تاريخ الإشعار:
                            <?= htmlspecialchars($notification['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </span>

                    </div>

                    <?php if (!$isRead): ?>

                        <form method="POST" class="read-form">

                            <input
                                type="hidden"
                                name="notification_id"
                                value="<?= (int) $notification['id'] ?>"
                            >

                            <button type="submit" class="read-button">
                                تحديد كمقروء
                            </button>

                        </form>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
