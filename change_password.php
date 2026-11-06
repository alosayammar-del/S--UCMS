<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('طلب غير صالح.');
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $message = 'يرجى تعبئة جميع الحقول.';
        $messageType = 'error';

    } elseif (strlen($newPassword) < 8) {
        $message = 'يجب ألا تقل كلمة المرور الجديدة عن 8 أحرف.';
        $messageType = 'error';

    } elseif ($newPassword !== $confirmPassword) {
        $message = 'كلمتا المرور الجديدتان غير متطابقتين.';
        $messageType = 'error';

    } else {
        try {
            $stmt = $pdo->prepare(
                "SELECT password_hash FROM users WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                $message = 'كلمة المرور الحالية غير صحيحة.';
                $messageType = 'error';

            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $update = $pdo->prepare(
                    "UPDATE users SET password_hash = ? WHERE id = ?"
                );
                $update->execute([$newHash, $userId]);

                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                $message = 'تم تغيير كلمة المرور بنجاح.';
                $messageType = 'success';
            }

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $message = 'حدث خطأ أثناء تغيير كلمة المرور.';
            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تغيير كلمة المرور | S-UCMS</title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Cairo', sans-serif;
    background: #f3f6fa;
    color: #243447;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px;
}

.password-card {
    width: 100%;
    max-width: 480px;
    background: white;
    border: 1px solid #e7edf3;
    border-radius: 17px;
    padding: 30px;
    box-shadow: 0 8px 30px rgba(16,44,70,.06);
}

.heading {
    text-align: center;
    margin-bottom: 25px;
}

.icon {
    width: 58px;
    height: 58px;
    background: #eaf3fb;
    color: #2675b9;
    border-radius: 15px;
    display: grid;
    place-items: center;
    font-size: 26px;
    margin: 0 auto 13px;
}

h1 {
    color: #102c46;
    font-size: 21px;
    margin-bottom: 7px;
}

.heading p {
    color: #8190a1;
    font-size: 12px;
}

.form-group {
    margin-bottom: 17px;
}

label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 7px;
    color: #243447;
}

input {
    width: 100%;
    padding: 12px;
    border: 1px solid #dce5ed;
    border-radius: 9px;
    font-family: inherit;
    font-size: 13px;
    outline: none;
}

input:focus {
    border-color: #2675b9;
}

button {
    width: 100%;
    padding: 12px;
    border: 0;
    border-radius: 9px;
    background: #102c46;
    color: white;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

button:hover {
    background: #183d5c;
}

.message {
    padding: 11px;
    border-radius: 8px;
    font-size: 12px;
    margin-bottom: 17px;
    text-align: center;
}

.success {
    background: #eaf8ef;
    color: #237443;
}

.error {
    background: #fff0ee;
    color: #bd5147;
}

.back {
    display: block;
    text-align: center;
    margin-top: 19px;
    color: #2675b9;
    text-decoration: none;
    font-size: 12px;
}

.note {
    color: #8190a1;
    font-size: 10px;
    margin-top: 15px;
    line-height: 1.8;
}
</style>
</head>

<body>

<div class="password-card">

    <div class="heading">
        <div class="icon">🔒</div>
        <h1>تغيير كلمة المرور</h1>
        <p>قم بتحديث كلمة المرور الخاصة بحسابك</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-group">
            <label>كلمة المرور الحالية</label>
            <input type="password" name="current_password"
                   autocomplete="current-password" required>
        </div>

        <div class="form-group">
            <label>كلمة المرور الجديدة</label>
            <input type="password" name="new_password"
                   autocomplete="new-password" minlength="8" required>
        </div>

        <div class="form-group">
            <label>تأكيد كلمة المرور الجديدة</label>
            <input type="password" name="confirm_password"
                   autocomplete="new-password" minlength="8" required>
        </div>

        <button type="submit">حفظ كلمة المرور الجديدة</button>

    </form>

    <div class="note">
        يجب أن تتكون كلمة المرور الجديدة من 8 أحرف على الأقل.
    </div>

    <a href="dashboard.php" class="back">العودة إلى لوحة التحكم</a>

</div>

</body>
</html>