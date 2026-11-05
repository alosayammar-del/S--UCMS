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

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$selectedCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$selectedStudent = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

try {
    // قائمة المقررات
    $courses = $pdo->query("
        SELECT id, course_code, course_name
        FROM courses
        ORDER BY course_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    // قائمة الطلاب
    $students = $pdo->query("
        SELECT
            u.id,
            u.full_name,
            st.student_number
        FROM students st
        INNER JOIN users u ON u.id = st.user_id
        ORDER BY u.full_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // إحصائيات المستخدمين
    $usersStats = $pdo->query("
        SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN status <> 'active' THEN 1 ELSE 0 END) AS inactive_users,
            SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) AS students_count,
            SUM(CASE WHEN role = 'faculty' THEN 1 ELSE 0 END) AS faculty_count,
            SUM(CASE WHEN role = 'staff' THEN 1 ELSE 0 END) AS staff_count
        FROM users
    ")->fetch(PDO::FETCH_ASSOC);

    // إحصائيات المقررات والتسجيل
    $courseStats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM courses) AS total_courses,
            (SELECT COUNT(*) FROM enrollments WHERE status = 'enrolled') AS total_enrollments,
            (SELECT COUNT(*) FROM course_instructors) AS instructor_assignments,
            (SELECT COUNT(*) FROM classrooms) AS total_classrooms,
            (SELECT COUNT(*) FROM schedules) AS total_schedules
    ")->fetch(PDO::FETCH_ASSOC);

    // إحصائيات الحضور
    $attendanceStats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN attendance_status = 'excused' THEN 1 ELSE 0 END) AS excused_count
        FROM attendance
    ")->fetch(PDO::FETCH_ASSOC);

    // إحصائيات الدرجات
    $gradeStats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            AVG(
                CASE
                    WHEN max_score > 0
                    THEN (score / max_score) * 100
                    ELSE NULL
                END
            ) AS average_percentage,
            MAX(
                CASE
                    WHEN max_score > 0
                    THEN (score / max_score) * 100
                    ELSE NULL
                END
            ) AS highest_percentage,
            MIN(
                CASE
                    WHEN max_score > 0
                    THEN (score / max_score) * 100
                    ELSE NULL
                END
            ) AS lowest_percentage
        FROM grades
    ")->fetch(PDO::FETCH_ASSOC);

    // إحصائيات الطلبات الجامعية
    $requestStats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS progress_count,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count
        FROM university_requests
    ")->fetch(PDO::FETCH_ASSOC);

    // إحصائيات التنبيهات الأكاديمية
    $alertStats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN risk_level = 'Satisfactory' THEN 1 ELSE 0 END) AS satisfactory_count,
            SUM(CASE WHEN risk_level = 'Needs Improvement' THEN 1 ELSE 0 END) AS improvement_count,
            SUM(CASE WHEN risk_level = 'Critical Risk' THEN 1 ELSE 0 END) AS critical_count
        FROM academic_alerts
    ")->fetch(PDO::FETCH_ASSOC);

    // تقرير التسجيل حسب المقرر
    $courseReportSql = "
        SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.credit_hours,
            COUNT(DISTINCT CASE WHEN e.status = 'enrolled' THEN e.id END) AS students_count,
            COUNT(DISTINCT ci.id) AS instructors_count
        FROM courses c
        LEFT JOIN enrollments e ON e.course_id = c.id
        LEFT JOIN course_instructors ci ON ci.course_id = c.id
        WHERE 1 = 1
    ";

    $courseParams = [];

    if ($selectedCourse > 0) {
        $courseReportSql .= " AND c.id = ?";
        $courseParams[] = $selectedCourse;
    }

    $courseReportSql .= "
        GROUP BY c.id, c.course_code, c.course_name, c.credit_hours
        ORDER BY c.course_code
    ";

    $courseReportStmt = $pdo->prepare($courseReportSql);
    $courseReportStmt->execute($courseParams);
    $courseReport = $courseReportStmt->fetchAll(PDO::FETCH_ASSOC);

    // تقرير الطلاب
    $studentReportSql = "
        SELECT
            u.id,
            u.full_name,
            st.student_number,
            st.department,
            COUNT(DISTINCT CASE WHEN e.status = 'enrolled' THEN e.id END) AS courses_count,
            COUNT(DISTINCT a.id) AS attendance_count,
            COUNT(DISTINCT g.id) AS grades_count,
            AVG(
                CASE
                    WHEN g.max_score > 0
                    THEN (g.score / g.max_score) * 100
                    ELSE NULL
                END
            ) AS average_percentage
        FROM students st
        INNER JOIN users u ON u.id = st.user_id
        LEFT JOIN enrollments e ON e.student_id = st.user_id
        LEFT JOIN attendance a ON a.enrollment_id = e.id
        LEFT JOIN grades g ON g.enrollment_id = e.id
        WHERE 1 = 1
    ";

    $studentParams = [];

    if ($selectedStudent > 0) {
        $studentReportSql .= " AND st.user_id = ?";
        $studentParams[] = $selectedStudent;
    }

    if ($selectedCourse > 0) {
        $studentReportSql .= "
            AND EXISTS (
                SELECT 1
                FROM enrollments selected_enrollment
                WHERE selected_enrollment.student_id = st.user_id
                  AND selected_enrollment.course_id = ?
            )
        ";
        $studentParams[] = $selectedCourse;
    }

    $studentReportSql .= "
        GROUP BY
            u.id,
            u.full_name,
            st.student_number,
            st.department
        ORDER BY u.full_name
    ";

    $studentReportStmt = $pdo->prepare($studentReportSql);
    $studentReportStmt->execute($studentParams);
    $studentReport = $studentReportStmt->fetchAll(PDO::FETCH_ASSOC);

    // أحدث الطلبات
    $latestRequests = $pdo->query("
        SELECT
            r.tracking_id,
            r.request_type,
            r.status,
            r.submitted_at,
            u.full_name
        FROM university_requests r
        LEFT JOIN users u ON u.id = r.student_id
        ORDER BY r.submitted_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die('حدث خطأ أثناء إعداد التقارير: ' . h($e->getMessage()));
}

$requestLabels = [
    'pending' => 'قيد الانتظار',
    'in_progress' => 'قيد المعالجة',
    'approved' => 'تمت الموافقة',
    'rejected' => 'مرفوض',
    'completed' => 'مكتمل'
];

$attendanceLabels = [
    'present' => 'حاضر',
    'absent' => 'غائب',
    'late' => 'متأخر',
    'excused' => 'بعذر'
];

$alertLabels = [
    'Satisfactory' => 'مستقر',
    'Needs Improvement' => 'يحتاج إلى تحسين',
    'Critical Risk' => 'خطر حرج'
];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التقارير والإحصائيات | S-UCMS</title>

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

        .back-link,
        .print-btn {
            display: inline-block;
            padding: 10px 17px;
            border-radius: 7px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
        }

        .back-link {
            color: #fff;
            background: #244d7c;
        }

        .print-btn {
            color: #fff;
            background: #16805d;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
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
            font-size: 26px;
        }

        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
            align-items: end;
        }

        label {
            display: block;
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 8px;
        }

        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #dce2eb;
            border-radius: 7px;
            font-family: inherit;
            background: #fff;
        }

        button {
            border: none;
            border-radius: 7px;
            padding: 11px 17px;
            font-family: inherit;
            font-size: 14px;
            cursor: pointer;
        }

        .primary {
            background: #244d7c;
            color: #fff;
        }

        .reset {
            display: inline-block;
            text-decoration: none;
            padding: 11px 17px;
            border-radius: 7px;
            background: #e8edf4;
            color: #334155;
            text-align: center;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 750px;
            border-collapse: collapse;
        }

        th,
        td {
            text-align: right;
            padding: 13px 11px;
            border-bottom: 1px solid #edf0f5;
            font-size: 13px;
        }

        th {
            background: #f5f7fb;
            color: #40516b;
            white-space: nowrap;
        }

        .empty {
            text-align: center;
            color: #718096;
            padding: 25px;
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 15px;
        }

        .report-item {
            background: #f7f9fc;
            border: 1px solid #edf0f5;
            padding: 15px;
            border-radius: 9px;
        }

        .report-item span {
            display: block;
            color: #718096;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .report-item strong {
            color: #244d7c;
            font-size: 20px;
        }

        .section-title {
            margin-top: 28px;
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

        @media print {
            body {
                background: #fff;
            }

            .container {
                width: 100%;
                margin: 0;
            }

            .header,
            .panel,
            .stat-card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
            }

            .back-link,
            .print-btn,
            .filters {
                display: none !important;
            }

            .table-wrapper {
                overflow: visible;
            }

            table {
                min-width: 0;
            }

            th,
            td {
                font-size: 10px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h1>التقارير والإحصائيات</h1>
        <p class="subtitle">
            عرض التقارير الأكاديمية والإدارية ومؤشرات أداء النظام الجامعي.
        </p>

        <a class="back-link" href="dashboard.php">العودة إلى لوحة التحكم</a>
        <button class="print-btn" onclick="window.print()">طباعة التقرير</button>
    </div>

    <div class="stats">

        <div class="stat-card">
            <span>إجمالي المستخدمين</span>
            <strong><?= (int)$usersStats['total_users'] ?></strong>
        </div>

        <div class="stat-card">
            <span>المستخدمون النشطون</span>
            <strong><?= (int)$usersStats['active_users'] ?></strong>
        </div>

        <div class="stat-card">
            <span>إجمالي الطلاب</span>
            <strong><?= (int)$usersStats['students_count'] ?></strong>
        </div>

        <div class="stat-card">
            <span>أعضاء هيئة التدريس</span>
            <strong><?= (int)$usersStats['faculty_count'] ?></strong>
        </div>

        <div class="stat-card">
            <span>المقررات الدراسية</span>
            <strong><?= (int)$courseStats['total_courses'] ?></strong>
        </div>

        <div class="stat-card">
            <span>التسجيلات النشطة</span>
            <strong><?= (int)$courseStats['total_enrollments'] ?></strong>
        </div>

        <div class="stat-card">
            <span>الجداول الدراسية</span>
            <strong><?= (int)$courseStats['total_schedules'] ?></strong>
        </div>

        <div class="stat-card">
            <span>القاعات الدراسية</span>
            <strong><?= (int)$courseStats['total_classrooms'] ?></strong>
        </div>

    </div>

    <div class="panel">
        <h2>التصفية حسب المقرر والطالب</h2>

        <form method="GET" action="admin_reports.php" class="filters">

            <div>
                <label>المقرر الدراسي</label>
                <select name="course_id">
                    <option value="0">جميع المقررات</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= (int)$course['id'] ?>"
                            <?= $selectedCourse === (int)$course['id'] ? 'selected' : '' ?>>
                            <?= h($course['course_code'] . ' - ' . $course['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>الطالب</label>
                <select name="student_id">
                    <option value="0">جميع الطلاب</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int)$student['id'] ?>"
                            <?= $selectedStudent === (int)$student['id'] ? 'selected' : '' ?>>
                            <?= h($student['full_name'] . ' - ' . $student['student_number']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="primary">تطبيق التصفية</button>

            <a href="admin_reports.php" class="reset">إعادة تعيين</a>

        </form>
    </div>

    <div class="panel">
        <h2>تقرير الحضور والدرجات</h2>

        <div class="report-grid">

            <div class="report-item">
                <span>إجمالي سجلات الحضور</span>
                <strong><?= (int)$attendanceStats['total'] ?></strong>
            </div>

            <?php foreach ($attendanceLabels as $key => $label): ?>
                <div class="report-item">
                    <span><?= h($label) ?></span>
                    <strong><?= (int)($attendanceStats[$key . '_count'] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>

            <div class="report-item">
                <span>إجمالي سجلات الدرجات</span>
                <strong><?= (int)$gradeStats['total'] ?></strong>
            </div>

            <div class="report-item">
                <span>متوسط الدرجات</span>
                <strong>
                    <?= $gradeStats['average_percentage'] !== null
                        ? number_format((float)$gradeStats['average_percentage'], 1) . '%'
                        : '—' ?>
                </strong>
            </div>

            <div class="report-item">
                <span>أعلى نسبة درجة</span>
                <strong>
                    <?= $gradeStats['highest_percentage'] !== null
                        ? number_format((float)$gradeStats['highest_percentage'], 1) . '%'
                        : '—' ?>
                </strong>
            </div>

            <div class="report-item">
                <span>أقل نسبة درجة</span>
                <strong>
                    <?= $gradeStats['lowest_percentage'] !== null
                        ? number_format((float)$gradeStats['lowest_percentage'], 1) . '%'
                        : '—' ?>
                </strong>
            </div>

        </div>
    </div>

    <div class="panel">
        <h2>تقرير الطلبات الجامعية</h2>

        <div class="report-grid">

            <div class="report-item">
                <span>إجمالي الطلبات</span>
                <strong><?= (int)$requestStats['total'] ?></strong>
            </div>

            <?php foreach ($requestLabels as $key => $label): ?>
                <div class="report-item">
                    <span><?= h($label) ?></span>
                    <strong><?= (int)($requestStats[$key . '_count'] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>

        </div>
    </div>

    <div class="panel">
        <h2>مؤشرات التنبيهات الأكاديمية</h2>

        <div class="report-grid">

            <div class="report-item">
                <span>إجمالي التنبيهات</span>
                <strong><?= (int)$alertStats['total'] ?></strong>
            </div>

            <?php foreach ($alertLabels as $key => $label): ?>
                <div class="report-item">
                    <span><?= h($label) ?></span>
                    <strong><?= (int)($alertStats[
                        $key === 'Satisfactory' ? 'satisfactory_count' :
                        ($key === 'Needs Improvement' ? 'improvement_count' : 'critical_count')
                    ] ?? 0) ?></strong>
                </div>
            <?php endforeach; ?>

        </div>
    </div>

    <div class="panel">
        <h2>تقرير المقررات والتسجيل</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>رمز المقرر</th>
                    <th>اسم المقرر</th>
                    <th>الساعات المعتمدة</th>
                    <th>عدد الطلاب المسجلين</th>
                    <th>أعضاء هيئة التدريس المسند إليهم</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($courseReport)): ?>
                    <tr>
                        <td colspan="5" class="empty">لا توجد بيانات للمقررات.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($courseReport as $row): ?>
                        <tr>
                            <td><?= h($row['course_code']) ?></td>
                            <td><?= h($row['course_name']) ?></td>
                            <td><?= (int)$row['credit_hours'] ?></td>
                            <td><?= (int)$row['students_count'] ?></td>
                            <td><?= (int)$row['instructors_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>تقرير الطلاب</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>اسم الطالب</th>
                    <th>الرقم الجامعي</th>
                    <th>القسم</th>
                    <th>المقررات المسجل بها</th>
                    <th>سجلات الحضور</th>
                    <th>سجلات الدرجات</th>
                    <th>متوسط الدرجات</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($studentReport)): ?>
                    <tr>
                        <td colspan="7" class="empty">لا توجد بيانات للطلاب.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($studentReport as $row): ?>
                        <tr>
                            <td><?= h($row['full_name']) ?></td>
                            <td><?= h($row['student_number']) ?></td>
                            <td><?= h($row['department'] ?: '—') ?></td>
                            <td><?= (int)$row['courses_count'] ?></td>
                            <td><?= (int)$row['attendance_count'] ?></td>
                            <td><?= (int)$row['grades_count'] ?></td>
                            <td>
                                <?= $row['average_percentage'] !== null
                                    ? number_format((float)$row['average_percentage'], 1) . '%'
                                    : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <h2>أحدث الطلبات الجامعية</h2>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>رقم التتبع</th>
                    <th>اسم الطالب</th>
                    <th>نوع الطلب</th>
                    <th>الحالة</th>
                    <th>تاريخ التقديم</th>
                </tr>
                </thead>

                <tbody>
                <?php if (empty($latestRequests)): ?>
                    <tr>
                        <td colspan="5" class="empty">لا توجد طلبات جامعية.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($latestRequests as $request): ?>
                        <tr>
                            <td><?= h($request['tracking_id']) ?></td>
                            <td><?= h($request['full_name'] ?? '—') ?></td>
                            <td><?= h($request['request_type']) ?></td>
                            <td><?= h($requestLabels[$request['status']] ?? $request['status']) ?></td>
                            <td><?= h($request['submitted_at']) ?></td>
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
