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

// التحقق من صلاحية الموظف
if (!$user || $user['role'] !== 'staff' || $user['status'] !== 'active') {
    header('Location: index.php');
    exit;
}

$fullName = $user['full_name'];

// إحصائيات الطلبات
$requestsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
    FROM university_requests
");
$requestsStmt->execute();
$requestStats = $requestsStmt->fetch(PDO::FETCH_ASSOC);

// إحصائيات إشعارات الموظف
$notificationsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread
    FROM notifications
    WHERE user_id = ?
");
$notificationsStmt->execute([$userId]);
$notificationStats = $notificationsStmt->fetch(PDO::FETCH_ASSOC);

$totalRequests = (int) ($requestStats['total'] ?? 0);
$pendingRequests = (int) ($requestStats['pending'] ?? 0);

$totalNotifications = (int) ($notificationStats['total'] ?? 0);
$unreadNotifications = (int) ($notificationStats['unread'] ?? 0);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>خدمات الموظف - S-UCMS</title>

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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            margin-bottom: 25px;
        }

        .section-title {
            color: #173b68;
            margin-bottom: 18px;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 18px;
        }

        .service-card {
            background: white;
            padding: 22px;
            border-radius: 13px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
            border: 1px solid #e8edf3;
            display: flex;
            flex-direction: column;
            min-height: 210px;
        }

        .service-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #eaf2fc;
            color: #2463a6;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 23px;
            margin-bottom: 15px;
        }

        .service-card h3 {
            margin: 0 0 10px;
            color: #173b68;
            font-size: 17px;
        }

        .service-card p {
            color: #64748b;
            font-size: 13px;
            line-height: 1.8;
            margin: 0 0 18px;
            flex: 1;
        }

        .service-link {
            display: block;
            text-align: center;
            padding: 11px;
            background: #2463a6;
            color: white;
            text-decoration: none;
            border-radius: 7px;
            font-size: 13px;
        }

        .service-link:hover {
            background: #173b68;
        }

        .info-section {
            background: white;
            padding: 22px;
            border-radius: 13px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .info-section h3 {
            color: #173b68;
            margin-top: 0;
        }

        .info-section p {
            color: #64748b;
            line-height: 1.9;
            font-size: 14px;
        }

        @media (max-width: 600px) {
            body {
                padding: 12px;
            }

            .header {
                padding: 20px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">

        <h2>خدمات الموظف</h2>

        <p>
            مرحبا، <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
        </p>

        <a href="dashboard.php" class="back-link">
            العودة إلى لوحة التحكم
        </a>

    </div>

    <div class="stats">

        <div class="stat-card">
            <h3>إجمالي الطلبات الجامعية</h3>
            <strong><?= $totalRequests ?></strong>
        </div>

        <div class="stat-card">
            <h3>الطلبات قيد المراجعة</h3>
            <strong><?= $pendingRequests ?></strong>
        </div>

        <div class="stat-card">
            <h3>إجمالي الإشعارات</h3>
            <strong><?= $totalNotifications ?></strong>
        </div>

        <div class="stat-card">
            <h3>الإشعارات غير المقروءة</h3>
            <strong><?= $unreadNotifications ?></strong>
        </div>

    </div>

    <div class="section">

        <h3 class="section-title">الخدمات المتاحة</h3>

        <div class="services-grid">

            <div class="service-card">

                <div class="service-icon">📋</div>

                <h3>إدارة الطلبات الجامعية</h3>

                <p>
                    عرض طلبات الطلاب ومراجعتها وتحديث حالتها
                    وإضافة ملاحظات الموظف.
                </p>

                <a href="staff_requests.php" class="service-link">
                    فتح الخدمة
                </a>

            </div>

            <div class="service-card">

                <div class="service-icon">🔔</div>

                <h3>إدارة الإشعارات</h3>

                <p>
                    متابعة الإشعارات والتنبيهات الخاصة بحساب الموظف
                    ومعرفة الإشعارات غير المقروءة.
                </p>

                <a href="staff_notifications.php" class="service-link">
                    فتح الخدمة
                </a>

            </div>

            <div class="service-card">

                <div class="service-icon">👤</div>

                <h3>الملف الشخصي</h3>

                <p>
                    الاطلاع على بيانات حساب الموظف والمعلومات
                    الأساسية المسجلة في النظام.
                </p>

                <a href="dashboard.php" class="service-link">
                    العودة إلى لوحة التحكم
                </a>

            </div>

            <div class="service-card">

                <div class="service-icon">🛠️</div>

                <h3>الدعم الفني</h3>

                <p>
                    متابعة المشكلات التقنية المتعلقة باستخدام
                    النظام والخدمات الإلكترونية.
                </p>

                <a href="mailto:support@ucms.local" class="service-link">
                    التواصل مع الدعم
                </a>

            </div>

        </div>

    </div>

    <div class="info-section">

        <h3>معلومات عن خدمات الموظف</h3>

        <p>
            توفر هذه الواجهة نقطة وصول موحدة إلى الخدمات المرتبطة
            بمهام الموظف داخل نظام إدارة الحرم الجامعي المتكامل.
            وتهدف إلى تسهيل متابعة الطلبات الجامعية والإشعارات
            والوصول إلى الخدمات المتاحة من مكان واحد.
        </p>

    </div>

</div>

</body>
</html>
