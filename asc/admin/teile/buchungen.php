<?php
/** Liste aller Buchungen und Anfragen. */
defined('ASC_ADMIN') || exit;

$filterStatus = $_GET['status'] ?? 'aktuell';
$filterTyp = $_GET['typ'] ?? '';
$suche = trim((string) ($_GET['q'] ?? ''));

$liste = array_filter($buchungen, function (array $b) use ($filterStatus, $filterTyp, $suche) {
    if ($filterStatus === 'aktuell') {
        // offene immer zeigen (auch wenn der Termin vorbei ist), bestätigte nur, solange sie anstehen
        $anstehend = $b['status'] === 'bestaetigt' && buchung_leistungsende($b) >= heute();
        if ($b['status'] !== 'offen' && !$anstehend) {
            return false;
        }
    } elseif ($filterStatus !== '' && $b['status'] !== $filterStatus) {
        return false;
    }
    if ($filterTyp !== '' && $b['typ'] !== $filterTyp) {
        return false;
    }
    if ($suche !== '') {
        $heu = mb_strtolower($b['id'] . ' ' . ($b['kunde']['name'] ?? '') . ' ' . ($b['kunde']['email'] ?? '') . ' ' . ($b['tier']['name'] ?? ''));
        if (!str_contains($heu, mb_strtolower($suche))) {
            return false;
        }
    }
    return true;
});

// Offene zuerst, dann nach Termin (nächster zuerst)
usort($liste, function ($a, $b) {
    $oa = $a['status'] === 'offen' ? 0 : 1;
    $ob = $b['status'] === 'offen' ? 0 : 1;
    if ($oa !== $ob) {
        return $oa <=> $ob;
    }
    $da = $a['typ'] === 'gassi' ? $a['datum'] . ' ' . $a['zeitfenster'] : $a['von'];
    $db = $b['typ'] === 'gassi' ? $b['datum'] . ' ' . $b['zeitfenster'] : $b['von'];
    return strcmp($da, $db);
});
?>
<h1>Buchungen &amp; Anfragen</h1>

<form class="filter" method="get">
  <input type="hidden" name="seite" value="buchungen">
  <div class="feld">
    <label for="f-status">Status</label>
    <select id="f-status" name="status">
      <option value="aktuell" <?= $filterStatus === 'aktuell' ? 'selected' : '' ?>>offen + anstehend</option>
      <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>alle</option>
      <?php foreach (STATUS as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="feld">
    <label for="f-typ">Art</label>
    <select id="f-typ" name="typ">
      <option value="">alle</option>
      <option value="gassi" <?= $filterTyp === 'gassi' ? 'selected' : '' ?>>Gassi/Besuch</option>
      <option value="betreuung" <?= $filterTyp === 'betreuung' ? 'selected' : '' ?>>Betreuung</option>
    </select>
  </div>
  <div class="feld">
    <label for="f-q">Suche</label>
    <input type="text" id="f-q" name="q" value="<?= h($suche) ?>" placeholder="Name, E-Mail, Tier, ID">
  </div>
  <button class="asc-btn asc-btn--primary asc-btn--small" type="submit">Filtern</button>
</form>

<?php if (!$liste): ?>
  <p class="hinweis">Keine Buchungen gefunden.</p>
<?php else: ?>
  <div class="tabelle-rahmen">
    <table>
      <thead>
        <tr><th>Termin</th><th>Leistung</th><th>Tier</th><th>Kunde</th><th class="zahl">Preis</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($liste as $b): ?>
        <tr>
          <td><a href="?seite=buchung&amp;id=<?= h(rawurlencode($b['id'])) ?>"><?= h(buchung_zeitraum_text($b)) ?></a><br><span class="klein"><?= h($b['id']) ?></span></td>
          <td><?= h($b['tarifName'] ?? $b['tarifId']) ?></td>
          <td><?= h($b['tier']['name'] ?: '–') ?> <span class="klein">(<?= h(TIERARTEN[$b['tier']['art']] ?? $b['tier']['art']) ?><?= ($b['anzahlTiere'] ?? 1) > 1 ? ', ' . (int) $b['anzahlTiere'] . ' Tiere' : '' ?>)</span></td>
          <td><?= h($b['kunde']['name'] ?: '(anonymisiert)') ?><br><span class="klein"><?= h($b['kunde']['telefon']) ?></span></td>
          <td class="zahl"><?= h(euro($b['preisGesamt'])) ?></td>
          <td><?= status_etikett($b['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<p class="klein"><?= count($buchungen) ?> Buchungen insgesamt. Personenbezogene Daten werden <?= (int) config('anonymisieren_nach_monaten') ?> Monate nach dem Termin automatisch entfernt.</p>
<form method="post" class="inline-form">
  <?= csrf_feld() ?>
  <input type="hidden" name="aktion" value="anonymisieren">
  <button class="asc-btn asc-btn--ghost asc-btn--small" type="submit">Anonymisierung jetzt ausführen</button>
</form>
<a class="asc-btn asc-btn--ghost asc-btn--small" href="?aktion=export">Alle Buchungen als CSV</a>
