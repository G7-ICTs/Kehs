<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$db = getDB();
require_once __DIR__ . '/../includes/ai_engine.php';
require_once __DIR__ . '/../includes/ai_writer.php';
require_once __DIR__ . '/../includes/timetable_generator.php';

$year = aiYear($db);
$ctx = aiSchoolContext($db);
$tab = in_array($_GET['tab'] ?? ($_POST['tab'] ?? ''), ['insights', 'planner', 'announce', 'docs'], true) ? ($_GET['tab'] ?? $_POST['tab']) : 'insights';
$annTypes = aiAnnouncementTypes();
$docTypes = aiDocumentTypes($db);
$annType = $_GET['ann_type'] ?? ($_POST['ann_type'] ?? 'exams_notice');
if (!isset($annTypes[$annType])) $annType = 'exams_notice';
$docType = $_GET['doc_type'] ?? ($_POST['doc_type'] ?? 'joining_instructions');
if (!isset($docTypes[$docType])) $docType = 'joining_instructions';
$lang = ($_POST['lang'] ?? $_GET['lang'] ?? 'en') === 'sw' ? 'sw' : 'en';

/** Collect posted field values (inputs are named f_<field>). */
function aiCollect(array $fields): array
{
    $in = [];
    foreach ($fields as $f) { $in[$f[0]] = trim((string)($_POST['f_' . $f[0]] ?? '')); }
    return $in;
}

$annDraft = null;
$docPreview = null;
$post = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

// ---------- Planner: apply suggested periods (optionally regenerate the timetable) ----------
if (in_array($post, ['apply_periods', 'apply_regenerate'], true)) {
    if (!$year) {
        flash('error', 'No current academic year is set.');
        redirect('/admin/ai_assistant.php?tab=planner');
    }
    $snap = aiSnapshot($db, $year);
    $rows = aiSuggestPeriods($snap);
    try {
        $db->beginTransaction();
        $upd = $db->prepare("UPDATE teacher_subject_class SET periods_per_week = ? WHERE id = ?");
        $changed = 0;
        foreach ($rows as $r) {
            if ($r['current'] !== $r['suggested']) { $upd->execute([$r['suggested'], $r['id']]); $changed++; }
        }
        $msg = "Updated periods per week for $changed assignment(s).";
        if ($post === 'apply_regenerate') {
            $db->prepare("DELETE FROM timetable WHERE academic_year_id = ?")->execute([(int)$year['id']]);
            $res = generateTimetable($db, (int)$year['id']);
            $msg .= " Timetable rebuilt: {$res['placed']} slot(s) placed (O-Level {$res['placed_by_level']['O-Level']}, A-Level {$res['placed_by_level']['A-Level']}).";
            if ($res['unplaced']) { $msg .= ' Could not fit ' . count($res['unplaced']) . ' assignment(s) - see Insights.'; }
        }
        $db->commit();
        logActivity($_SESSION['user_id'], $post === 'apply_regenerate' ? 'ai_plan_and_regenerate' : 'ai_apply_periods', $msg);
        flash('success', $msg);
    } catch (Throwable $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        appLog('Smart planner failed: ' . $ex->getMessage(), 'ERROR');
        flash('error', 'Could not apply the plan: ' . $ex->getMessage());
    }
    redirect('/admin/ai_assistant.php?tab=planner');
}

// ---------- Announcements: draft, then post ----------
if ($post === 'announce_generate') {
    $in = aiCollect(array_map(fn($f) => $f, $annTypes[$annType]['fields']));
    [$title, $body] = aiBuildAnnouncement($annType, $lang, $in, $ctx);
    $annDraft = ['title' => $title, 'body' => $body, 'audience' => $annTypes[$annType]['audience']];
    $tab = 'announce';
}
if ($post === 'post_announcement') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $audience = in_array($_POST['audience'] ?? '', ['all', 'teacher', 'parent', 'student'], true) ? $_POST['audience'] : 'all';
    if ($title === '' || $body === '') {
        flash('error', 'An announcement needs a title and a message.');
        redirect('/admin/ai_assistant.php?tab=announce');
    }
    $db->prepare("INSERT INTO announcements (title, body, audience, posted_by) VALUES (?, ?, ?, ?)")
       ->execute([mb_substr($title, 0, 150), $body, $audience, $_SESSION['user_id']]);
    logActivity($_SESSION['user_id'], 'post_announcement', "Posted (Smart Assistant): $title");
    flash('success', 'Announcement posted.');
    redirect('/admin/announcements.php');
}

// ---------- Documents: preview / print / Word / save ----------
if (in_array($post, ['doc_preview', 'doc_print', 'doc_word', 'doc_save'], true)) {
    $in = aiCollect(array_map(fn($f) => $f, $docTypes[$docType]['fields']));
    [$dTitle, $dCat, $dBody] = aiBuildDocument($db, $docType, $lang, $in, $ctx);
    $inner = aiDocumentInner($dTitle, $dBody, $ctx);
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $dTitle), '_') ?: 'document';

    if ($post === 'doc_print') {
        header('Content-Type: text/html; charset=utf-8');
        echo aiDocumentFull($dTitle, $inner, true);
        exit;
    }
    if ($post === 'doc_word') {
        header('Content-Type: application/msword');
        header('Content-Disposition: attachment; filename="' . $slug . '.doc"');
        echo aiDocumentFull($dTitle, $inner);
        exit;
    }
    if ($post === 'doc_save') {
        $fileName = $slug . '_' . date('Ymd_His') . '.doc';
        if (!is_dir(UPLOAD_DOCS_DIR)) { @mkdir(UPLOAD_DOCS_DIR, 0755, true); }
        if (@file_put_contents(UPLOAD_DOCS_DIR . $fileName, aiDocumentFull($dTitle, $inner)) === false) {
            flash('error', 'Could not save the document - the uploads/documents folder is not writable.');
            redirect('/admin/ai_assistant.php?tab=docs&doc_type=' . urlencode($docType));
        }
        $db->prepare("INSERT INTO documents (title, category, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)")
           ->execute([mb_substr($dTitle, 0, 150), $dCat, $fileName, 'uploads/documents/' . $fileName, $_SESSION['user_id']]);
        logActivity($_SESSION['user_id'], 'upload_document', "Generated document (Smart Assistant): $dTitle");
        flash('success', 'Document saved to Documents - parents, students and teachers can now download it.');
        redirect('/admin/documents.php');
    }
    $docPreview = ['title' => $dTitle, 'inner' => $inner];
    $tab = 'docs';
}

// ---------- Data for display ----------
$snap = aiSnapshot($db, $year);
$cov = aiCoverage($db, $year ? (int)$year['id'] : null);
$demand = aiTeacherDemand($snap);

/** Render the input fields of a type. */
function aiRenderFields(array $fields): void
{
    foreach ($fields as [$name, $label, $type, $default, $required]) {
        $val = $_POST['f_' . $name] ?? ($type === 'select' ? '' : $default);
        echo '<div class="mb-2"><label class="form-label small mb-1">' . e($label) . ($required ? ' *' : '') . '</label>';
        if ($type === 'textarea') {
            echo '<textarea class="form-control" rows="4" name="f_' . e($name) . '"' . ($required ? ' required' : '') . '>' . e($val) . '</textarea>';
        } elseif ($type === 'select') {
            echo '<select class="form-select" name="f_' . e($name) . '"' . ($required ? ' required' : '') . '>';
            foreach ($default as $k => $lbl) {
                echo '<option value="' . e((string)$k) . '"' . ((string)$val === (string)$k ? ' selected' : '') . '>' . e($lbl) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input class="form-control" type="' . e($type) . '" name="f_' . e($name) . '" value="' . e($val) . '"' . ($required ? ' required' : '') . '>';
        }
        echo '</div>';
    }
}

$levelStyle = ['critical' => ['danger', 'bi-exclamation-octagon'], 'warning' => ['warning', 'bi-exclamation-triangle'],
               'info' => ['info', 'bi-info-circle'], 'success' => ['success', 'bi-check-circle']];

$pageTitle = 'Smart Assistant';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
  <div>
    <h3 class="mb-0"><i class="bi bi-stars me-2 text-warning"></i>Smart Assistant</h3>
    <small class="text-muted">Decision support, timetable planning, and announcement &amp; document drafting. Runs inside the system, offline - suggestions only; nothing changes until you confirm.</small>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <?php foreach (['insights' => ['Insights', 'bi-lightbulb'], 'planner' => ['Timetable Planner', 'bi-calendar-check'], 'announce' => ['Announcements', 'bi-megaphone'], 'docs' => ['Documents', 'bi-file-earmark-text']] as $k => [$lbl, $ic]): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><i class="bi <?= $ic ?> me-1"></i><?= $lbl ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'insights'):
  $insights = aiInsights($snap, $cov); ?>
  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="dash-card text-center"><div class="metric-num text-primary"><?= (int)$snap['students_total'] ?></div><div class="text-muted small">Active students</div></div></div>
    <div class="col-6 col-lg-3"><div class="dash-card text-center"><div class="metric-num text-success"><?= count($snap['classes']) ?></div><div class="text-muted small">Classes</div></div></div>
    <div class="col-6 col-lg-3"><div class="dash-card text-center"><div class="metric-num text-warning"><?= $demand['have'] ?> <small class="fs-6 text-muted">/ <?= $demand['needed'] ?> needed</small></div><div class="text-muted small">Teachers</div></div></div>
    <div class="col-6 col-lg-3"><div class="dash-card text-center"><div class="metric-num <?= $cov['pct'] >= 100 ? 'text-success' : 'text-danger' ?>"><?= (int)$cov['pct'] ?>%</div><div class="text-muted small">Timetable coverage</div></div></div>
  </div>
  <div class="dash-card">
    <div class="dash-title mb-2">Findings &amp; recommendations<?= $year ? ' – ' . e($year['year_name']) : '' ?></div>
    <?php foreach ($insights as $i): [$cls, $icon] = $levelStyle[$i['level']]; ?>
      <div class="alert alert-<?= $cls ?> py-2 d-flex justify-content-between align-items-center gap-2 mb-2">
        <div><i class="bi <?= $icon ?> me-1"></i><strong><?= e($i['title']) ?></strong>
          <div class="small"><?= e($i['detail']) ?></div></div>
        <?php if ($i['link']): ?><a class="btn btn-sm btn-outline-dark text-nowrap" href="<?= e($i['link']) ?>"><?= e($i['action']) ?></a><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$insights): ?><p class="text-muted mb-0">Nothing to report yet - add classes, students, teachers and assignments first.</p><?php endif; ?>
  </div>
  <p class="small text-muted mt-2">Rules used: a class holds about <?= AI_CLASS_CAPACITY ?> students; a teacher should teach at most <?= AI_MAX_TEACHER_LOAD ?> periods a week;
    a class timetable should be filled to at most <?= (int)(AI_TARGET_UTILISATION * 100) ?>%. They are set at the top of <code>includes/ai_engine.php</code>.</p>

<?php elseif ($tab === 'planner'):
  $plan = aiSuggestPeriods($snap);
  $changes = count(array_filter($plan, fn($r) => $r['current'] !== $r['suggested'])); ?>
  <div class="dash-card mb-3">
    <div class="dash-title">How the planner decides</div>
    <p class="small text-muted mb-2">It looks at every class's students and subjects. Core subjects (Mathematics, English, sciences) get more weekly time. If a class's subjects would
      fill more than <?= (int)(AI_TARGET_UTILISATION * 100) ?>% of its weekly slots (O-Level: <?= AI_O_SLOTS ?> forty-five-minute periods; A-Level: <?= AI_A_SLOTS ?> ninety-minute sessions,
      measured on its busiest combination) everything is scaled down evenly so the timetable can be completed. Bigger classes are scheduled first.</p>
    <div class="row g-2 text-center">
      <div class="col-4"><div class="metric-num"><?= count($plan) ?></div><div class="small text-muted">Assignments</div></div>
      <div class="col-4"><div class="metric-num text-warning"><?= $changes ?></div><div class="small text-muted">Would change</div></div>
      <div class="col-4"><div class="metric-num <?= $demand['needed'] > $demand['have'] ? 'text-danger' : 'text-success' ?>"><?= $demand['needed'] ?></div><div class="small text-muted">Teachers needed (have <?= $demand['have'] ?>)</div></div>
    </div>
  </div>
  <?php if (!$plan): ?>
    <div class="alert alert-info">No teacher assignments for the current year yet. <a href="assign_teacher.php">Assign teachers</a> first.</div>
  <?php else: ?>
    <div class="dash-card mb-3">
      <div class="table-responsive" style="max-height:480px">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Class</th><th>Students</th><th>Subject</th><th>Teacher</th><th>Now</th><th>Suggested</th></tr></thead>
          <tbody>
          <?php foreach ($plan as $r): ?>
            <tr class="<?= $r['current'] !== $r['suggested'] ? 'table-warning' : '' ?>">
              <td><?= e($r['class']) ?> <span class="badge <?= $r['level'] === 'A-Level' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= e($r['level']) ?></span></td>
              <td><?= (int)$r['students'] ?></td><td><?= e($r['subject']) ?></td><td><?= e($r['teacher']) ?></td>
              <td><?= (int)$r['current'] ?></td><td><strong><?= (int)$r['suggested'] ?></strong> <small class="text-muted"><?= $r['level'] === 'A-Level' ? '× 90 min' : '× 45 min' ?></small></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <form method="POST" onsubmit="return confirm('Apply the suggested periods per week? The timetable itself is not touched.');">
        <input type="hidden" name="action" value="apply_periods"><button class="btn btn-outline-primary"><i class="bi bi-check2 me-1"></i>Apply suggested periods</button>
      </form>
      <form method="POST" onsubmit="return confirm('Apply the suggested periods AND rebuild the whole timetable from scratch? Every existing timetable entry for this year (including manual ones) will be deleted.');">
        <input type="hidden" name="action" value="apply_regenerate"><button class="btn btn-primary"><i class="bi bi-magic me-1"></i>Apply &amp; rebuild timetable</button>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'announce'):
  $def = $annTypes[$annType]; ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="dash-card">
        <div class="dash-title mb-2">What is the announcement about?</div>
        <div class="d-flex flex-wrap gap-1 mb-3">
          <?php foreach ($annTypes as $k => $a): ?>
            <a class="btn btn-sm <?= $k === $annType ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?tab=announce&ann_type=<?= $k ?>&lang=<?= $lang ?>"><?= e($a['label']) ?></a>
          <?php endforeach; ?>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="announce_generate"><input type="hidden" name="tab" value="announce"><input type="hidden" name="ann_type" value="<?= e($annType) ?>">
          <div class="mb-2"><label class="form-label small mb-1">Language</label>
            <select class="form-select" name="lang"><option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option><option value="sw" <?= $lang === 'sw' ? 'selected' : '' ?>>Kiswahili</option></select></div>
          <?php aiRenderFields($def['fields']); ?>
          <button class="btn btn-primary mt-2"><i class="bi bi-stars me-1"></i>Write announcement</button>
        </form>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="dash-card">
        <div class="dash-title mb-2">Draft</div>
        <?php if ($annDraft): ?>
          <form method="POST">
            <input type="hidden" name="action" value="post_announcement">
            <div class="mb-2"><label class="form-label small mb-1">Title</label><input class="form-control" name="title" maxlength="150" value="<?= e($annDraft['title']) ?>" required></div>
            <div class="mb-2"><label class="form-label small mb-1">Message (edit as you like)</label><textarea class="form-control" name="body" rows="12" required><?= e($annDraft['body']) ?></textarea></div>
            <div class="mb-3"><label class="form-label small mb-1">Send to</label>
              <select class="form-select" name="audience" style="max-width:240px">
                <?php foreach (['all' => 'Everyone', 'teacher' => 'Teachers', 'parent' => 'Parents', 'student' => 'Students'] as $k => $lbl): ?>
                  <option value="<?= $k ?>" <?= $annDraft['audience'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select></div>
            <button class="btn btn-success"><i class="bi bi-megaphone me-1"></i>Post announcement</button>
          </form>
        <?php else: ?><p class="text-muted mb-0">Choose a type, fill in the details and click <em>Write announcement</em>. You can edit the draft before posting.</p><?php endif; ?>
      </div>
    </div>
  </div>

<?php else: /* docs */
  $def = $docTypes[$docType]; ?>
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="dash-card">
        <div class="dash-title mb-2">Which document?</div>
        <div class="d-flex flex-column gap-1 mb-3">
          <?php foreach ($docTypes as $k => $d): ?>
            <a class="btn btn-sm text-start <?= $k === $docType ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?tab=docs&doc_type=<?= $k ?>&lang=<?= $lang ?>"><?= e($d['label']) ?></a>
          <?php endforeach; ?>
        </div>
        <form method="POST" id="docForm">
          <input type="hidden" name="tab" value="docs"><input type="hidden" name="doc_type" value="<?= e($docType) ?>">
          <?php if ($def['letter']): ?>
            <div class="mb-2"><label class="form-label small mb-1">Language</label>
              <select class="form-select" name="lang"><option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option><option value="sw" <?= $lang === 'sw' ? 'selected' : '' ?>>Kiswahili</option></select></div>
          <?php endif; ?>
          <?php aiRenderFields($def['fields']); ?>
          <button class="btn btn-primary mt-2" name="action" value="doc_preview"><i class="bi bi-eye me-1"></i>Generate preview</button>
        </form>
      </div>
    </div>
    <div class="col-lg-8">
      <?php if ($docPreview): ?>
        <div class="dash-card mb-2 d-flex gap-2 flex-wrap align-items-center">
          <button class="btn btn-sm btn-outline-secondary" form="docForm" name="action" value="doc_print" formtarget="_blank"><i class="bi bi-printer me-1"></i>Print</button>
          <button class="btn btn-sm btn-outline-primary" form="docForm" name="action" value="doc_word"><i class="bi bi-file-earmark-word me-1"></i>Download Word</button>
          <button class="btn btn-sm btn-success" form="docForm" name="action" value="doc_save"><i class="bi bi-folder-plus me-1"></i>Save to Documents</button>
          <small class="text-muted ms-auto">"Save to Documents" makes it downloadable from every portal.</small>
        </div>
        <div class="paper"><?= $docPreview['inner'] ?></div>
      <?php else: ?>
        <div class="dash-card"><p class="text-muted mb-0">Pick a document, fill in the details and click <em>Generate preview</em>. Class lists, timetables and reports are filled in from the live system data.</p></div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
