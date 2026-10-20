/* ASC – Animal Sitter Club: Navigation, Preise, Kalender und Formulare. */
(function () {
  'use strict';

  var TIERARTEN = { hund: 'Hund', katze: 'Katze', kleintier: 'Kleintier', sonstiges: 'Sonstiges' };
  var MONATE = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
  var WOCHENTAGE_KURZ = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
  var WOCHENTAGE = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function el(tag, attrs, kinder) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else if (attrs[k] !== false && attrs[k] != null) e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (kinder || []).forEach(function (k) { if (k) e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k); });
    return e;
  }

  function euro(betrag) {
    var hatCent = Math.abs(betrag - Math.round(betrag)) > 0.001;
    return betrag.toLocaleString('de-DE', { minimumFractionDigits: hatCent ? 2 : 0, maximumFractionDigits: 2 }) + ' €';
  }

  function holeJson(url, optionen) {
    return fetch(url, Object.assign({ headers: { Accept: 'application/json' }, credentials: 'same-origin' }, optionen || {}))
      .then(function (r) {
        return r.json().catch(function () { return {}; }).then(function (daten) { return { ok: r.ok, status: r.status, daten: daten }; });
      });
  }

  /* ---------- Datum (lokal, ohne Zeitzonen-Überraschungen) ---------- */
  function isoDatum(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function ausIso(s) { var t = s.split('-'); return new Date(+t[0], +t[1] - 1, +t[2]); }
  function wochentagIndex(d) { return (d.getDay() + 6) % 7; } // 0 = Montag
  function datumLang(s) { var d = ausIso(s); return WOCHENTAGE[wochentagIndex(d)] + ', ' + d.getDate() + '. ' + MONATE[d.getMonth()] + ' ' + d.getFullYear(); }
  function datumKurz(s) { var d = ausIso(s); return WOCHENTAGE_KURZ[wochentagIndex(d)] + ', ' + d.getDate() + '. ' + MONATE[d.getMonth()]; }

  /* ---------- Navigation ---------- */
  var schalter = $('.nav-schalter');
  if (schalter) {
    schalter.addEventListener('click', function () {
      var nav = $('#hauptnav');
      var offen = nav.classList.toggle('offen');
      schalter.setAttribute('aria-expanded', offen ? 'true' : 'false');
    });
  }

  /* ---------- Tarife ---------- */
  var tarifeVersprechen = null;
  function tarife() {
    if (!tarifeVersprechen) {
      tarifeVersprechen = holeJson('/api/tarife.php').then(function (r) {
        if (!r.ok) throw new Error('Tarife nicht verfügbar');
        return r.daten.tarife || [];
      });
    }
    return tarifeVersprechen;
  }

  function einheitText(t) {
    return t.einheit || '';
  }

  // „ab X €“-Angaben auf der Startseite aktualisieren
  if ($('[data-preis-ab]')) {
    tarife().then(function (liste) {
      $$('[data-preis-ab]').forEach(function (e) {
        var ids = e.getAttribute('data-preis-ab').split(',');
        var preise = liste.filter(function (t) { return ids.indexOf(t.id) !== -1; }).map(function (t) { return t.preis; });
        if (!preise.length) return;
        var proTag = liste.some(function (t) { return ids.indexOf(t.id) !== -1 && t.abrechnung !== 'pro-termin'; });
        e.textContent = 'ab ' + euro(Math.min.apply(null, preise)) + (proTag && /pro Tag/.test(e.textContent) ? ' pro Tag' : '');
      });
    }).catch(function () { /* statischer Text bleibt stehen */ });
  }

  // Preisseite
  var tarifTabelle = $('#tarif-tabelle');
  if (tarifTabelle) {
    tarife().then(function (liste) {
      var tbody = $('tbody', tarifTabelle);
      tbody.innerHTML = '';
      liste.forEach(function (t) {
        var preis = euro(t.preis);
        var tr = el('tr', {}, [
          el('td', {}, [el('strong', { text: t.name }), el('br'), el('span', { class: 'klein', text: t.beschreibung })]),
          el('td', { text: t.tierarten.map(function (a) { return TIERARTEN[a] || a; }).join(', ') }),
          el('td', { text: einheitText(t) }),
          el('td', { class: 'zahl' }, [
            el('span', { class: 'preis', text: preis }),
            t.staffel ? el('span', { class: 'klein', text: ' · ab ' + t.staffel.abTagen + ' Tagen ' + euro(t.staffel.preis) + '/Tag' }) : null
          ]),
          el('td', {}, [
            t.art === 'direkt'
              ? el('a', { href: '/buchen', class: 'etikett direkt', text: 'direkt buchbar' })
              : el('a', { href: '/anfrage', class: 'etikett', text: 'Anfrage' })
          ])
        ]);
        tbody.appendChild(tr);
      });

      // Zuschläge zusammenfassen: gleicher Name + Preis -> eine Zeile
      var zuschlaege = {};
      liste.forEach(function (t) {
        t.zuschlaege.forEach(function (z) {
          var schluessel = z.name + '|' + z.preis + '|' + z.regel;
          (zuschlaege[schluessel] = zuschlaege[schluessel] || { z: z, tarife: [] }).tarife.push(t.name);
        });
      });
      var ztbody = $('#zuschlag-tabelle tbody');
      ztbody.innerHTML = '';
      Object.keys(zuschlaege).forEach(function (k) {
        var e = zuschlaege[k];
        var zusatz = e.z.regel === 'pro-weiteres-tier' ? ' (je weiteres Tier)' : e.z.regel === 'sonn-feiertag' ? ' (je Sonn-/Feiertag)' : '';
        ztbody.appendChild(el('tr', {}, [
          el('td', { text: e.z.name + zusatz }),
          el('td', { text: e.tarife.join(', ') }),
          el('td', { class: 'zahl', text: '+ ' + euro(e.z.preis) })
        ]));
      });
      if (!ztbody.children.length) ztbody.appendChild(el('tr', {}, [el('td', { colspan: '3', text: 'Keine Zuschläge.' })]));

      var staffel = liste.filter(function (t) { return t.staffel; });
      var sh = $('#staffel-hinweis');
      if (sh && staffel.length) {
        sh.textContent = staffel.map(function (t) {
          return t.name + ': ab ' + t.staffel.abTagen + ' Tagen nur ' + euro(t.staffel.preis) + ' statt ' + euro(t.preis) + ' pro Tag';
        }).join('; ') + '.';
      } else if (sh) {
        sh.remove();
      }
    }).catch(function () {
      $('tbody', tarifTabelle).innerHTML = '<tr><td colspan="5">Die Preise konnten gerade nicht geladen werden. Bitte versuch es später noch einmal.</td></tr>';
    });
  }

  /* ---------- Danke-Seite ---------- */
  if (document.body.dataset.seite === 'danke') {
    var p = new URLSearchParams(location.search);
    if (p.get('typ') === 'betreuung') {
      $('#danke-text').textContent = 'Wir schauen, ob wir die Betreuung übernehmen können, und melden uns innerhalb von 24 Stunden per E-Mail.';
    }
    if (p.get('id')) $('#danke-id').textContent = 'Deine Vorgangsnummer: ' + p.get('id') + '.';
  }

  /* ---------- Formulare ---------- */
  var form = $('form[data-formular]');
  if (!form) return;
  var typ = form.getAttribute('data-formular');
  var tarifSelect = $('#tarifId', form);
  var optionenBox = $('[data-optionen]', form);
  var alleTarife = [];

  // Formular-Token für den Spam-Schutz
  function tokenHolen() {
    return holeJson('/api/token.php').then(function (r) {
      if (r.ok) $('[data-form-token]', form).value = r.daten.formToken;
    });
  }
  tokenHolen();

  function gewaehlterTarif() {
    return alleTarife.filter(function (t) { return t.id === tarifSelect.value; })[0] || null;
  }

  function optionenZeigen() {
    var t = gewaehlterTarif();
    var vorher = $$('input[name="optionen[]"]:checked', form).map(function (i) { return i.value; });
    optionenBox.innerHTML = '';
    if (!t) return;
    t.zuschlaege.filter(function (z) { return z.regel === 'optional'; }).forEach(function (z) {
      optionenBox.appendChild(el('label', { class: 'auswahl' }, [
        el('input', { type: 'checkbox', name: 'optionen[]', value: z.id, checked: vorher.indexOf(z.id) !== -1 }),
        z.name + ' (+ ' + euro(z.preis) + ')'
      ]));
    });
  }

  function tarifeFuellen(liste) {
    var art = tarifSelect.getAttribute('data-tarife');
    var tierart = typ === 'betreuung' ? (($('input[name="tierart"]:checked', form) || {}).value || null) : null;
    var passende = liste.filter(function (t) { return t.art === art && (!tierart || t.tierarten.indexOf(tierart) !== -1); });
    var alt = tarifSelect.value;
    tarifSelect.innerHTML = '';
    if (art === 'anfrage') tarifSelect.appendChild(el('option', { value: '', text: '– bitte wählen –' }));
    passende.forEach(function (t) {
      tarifSelect.appendChild(el('option', { value: t.id, text: t.name + ' – ' + euro(t.preis) + ' / ' + einheitText(t) }));
    });
    if (passende.some(function (t) { return t.id === alt; })) tarifSelect.value = alt;
    optionenZeigen();
  }

  tarife().then(function (liste) {
    alleTarife = liste;
    tarifeFuellen(liste);
    var vorgabe = new URLSearchParams(location.search).get('tarif');
    if (vorgabe && liste.some(function (t) { return t.id === vorgabe; })) tarifSelect.value = vorgabe;
    if (typ === 'betreuung') tarifVorschlagen();
    optionenZeigen();
    preisAktualisieren();
  }).catch(function () {
    fehlerZeigen({ _: 'Die Tarife konnten nicht geladen werden. Bitte lade die Seite neu.' });
  });

  tarifSelect.addEventListener('change', function () {
    tarifSelect.dataset.manuell = '1';
    optionenZeigen();
    preisAktualisieren();
  });
  form.addEventListener('change', function (ev) {
    if (ev.target === tarifSelect) return;
    if (typ === 'betreuung' && (ev.target.name === 'tierart' || ev.target.name === 'von' || ev.target.name === 'bis')) {
      if (ev.target.name === 'tierart') tarifeFuellen(alleTarife);
      tarifVorschlagen();
    }
    preisAktualisieren();
  });
  form.addEventListener('input', function (ev) {
    if (ev.target.name === 'anzahlTiere') preisAktualisieren();
  });

  /* Live-Preis über den Server (eine Rechenlogik für Anzeige und Buchung) */
  var preisTimer = null;
  var preisAnfrage = 0;
  function preisAktualisieren() {
    clearTimeout(preisTimer);
    preisTimer = setTimeout(function () {
      var t = gewaehlterTarif();
      var gesamt = $('#preis-gesamt');
      var posten = $('#preis-posten');
      if (!t) { gesamt.textContent = '–'; posten.innerHTML = ''; return; }
      var daten = {
        tarifId: t.id,
        anzahlTiere: (form.elements.anzahlTiere || {}).value || 1,
        optionen: $$('input[name="optionen[]"]:checked', form).map(function (i) { return i.value; })
      };
      if (typ === 'gassi') {
        daten.datum = form.elements.datum.value;
      } else {
        daten.von = form.elements.von.value;
        daten.bis = form.elements.bis.value;
        if (!daten.von || !daten.bis || daten.bis < daten.von) {
          gesamt.textContent = '–';
          posten.innerHTML = '';
          posten.appendChild(el('li', { text: 'Gib einen Zeitraum an, dann rechnen wir den Preis aus.' }));
          return;
        }
      }
      var nr = ++preisAnfrage;
      holeJson('/api/preis.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(daten) })
        .then(function (r) {
          if (nr !== preisAnfrage || !r.ok) return;
          var d = r.daten;
          gesamt.textContent = euro(d.gesamt) + (typ === 'betreuung' ? ' für ' + d.tage + (d.tage === 1 ? ' Tag' : ' Tage') : '');
          posten.innerHTML = '';
          if (d.posten.length > 1) {
            d.posten.forEach(function (p) { posten.appendChild(el('li', { text: p.text + ': ' + euro(p.betrag) })); });
          }
        });
    }, 150);
  }

  /* Tarif-Vorschlag für Betreuungsanfragen */
  function tarifVorschlagen() {
    if (tarifSelect.dataset.manuell) return;
    var von = form.elements.von.value, bis = form.elements.bis.value;
    var tierart = ($('input[name="tierart"]:checked', form) || {}).value;
    var verfuegbar = $$('option', tarifSelect).map(function (o) { return o.value; });
    var vorschlag = null;
    if (tierart === 'kleintier' && verfuegbar.indexOf('kleintier') !== -1) {
      vorschlag = 'kleintier';
    } else if (von && bis && bis >= von) {
      var tage = Math.round((ausIso(bis) - ausIso(von)) / 864e5) + 1;
      var start = wochentagIndex(ausIso(von));
      if (tage === 1) vorschlag = 'tagesbetreuung';
      else if (tage <= 3 && start === 4) vorschlag = 'wochenende';
      else vorschlag = 'ferien';
    }
    if (vorschlag && verfuegbar.indexOf(vorschlag) !== -1) {
      tarifSelect.value = vorschlag;
      optionenZeigen();
    }
  }

  if (typ === 'betreuung') {
    var heute = isoDatum(new Date());
    form.elements.von.min = heute;
    form.elements.bis.min = heute;
    form.elements.von.addEventListener('change', function () {
      form.elements.bis.min = form.elements.von.value || heute;
      if (form.elements.bis.value && form.elements.bis.value < form.elements.von.value) form.elements.bis.value = form.elements.von.value;
    });
  }

  /* Fehleranzeige */
  function fehlerZuruecksetzen() {
    $$('[data-fehler-fuer]', form).forEach(function (p) { p.hidden = true; p.textContent = ''; });
    $$('[aria-invalid]', form).forEach(function (i) { i.removeAttribute('aria-invalid'); });
    var box = $('#formular-fehler');
    box.hidden = true;
    box.textContent = '';
  }

  function fehlerZeigen(fehler) {
    var box = $('#formular-fehler');
    var erstes = null;
    var allgemein = [];
    Object.keys(fehler).forEach(function (feld) {
      var ziel = $('[data-fehler-fuer="' + feld + '"]', form);
      if (ziel) {
        ziel.textContent = fehler[feld];
        ziel.hidden = false;
        var eingabe = form.elements[feld];
        if (eingabe && eingabe.setAttribute) {
          eingabe.setAttribute('aria-invalid', 'true');
          erstes = erstes || eingabe;
        }
        erstes = erstes || ziel;
      } else {
        allgemein.push(fehler[feld]);
      }
    });
    var anzahl = Object.keys(fehler).length;
    box.textContent = allgemein.length ? allgemein.join(' ') : (anzahl === 1 ? 'Bitte prüfe das markierte Feld.' : 'Bitte prüfe die ' + anzahl + ' markierten Felder.');
    box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (erstes && erstes.focus && !allgemein.length) setTimeout(function () { erstes.focus({ preventScroll: true }); }, 300);
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    fehlerZuruecksetzen();
    var knopf = $('button[type="submit"]', form);
    var daten = {};
    new FormData(form).forEach(function (wert, schluessel) {
      if (schluessel === 'optionen[]') (daten.optionen = daten.optionen || []).push(wert);
      else daten[schluessel] = wert;
    });
    knopf.disabled = true;
    var knopfText = knopf.textContent;
    knopf.textContent = 'Wird gesendet …';
    holeJson(form.getAttribute('action'), {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(daten)
    }).then(function (r) {
      if (r.ok && r.daten.ok) {
        location.href = r.daten.weiter;
        return;
      }
      fehlerZeigen((r.daten && r.daten.fehler) || { _: 'Da ist etwas schiefgegangen. Bitte versuch es noch einmal.' });
      if (r.daten && r.daten.fehler && r.daten.fehler._) tokenHolen();
      if (typ === 'gassi' && r.daten && r.daten.fehler && r.daten.fehler.datum) kalenderLaden();
      knopf.disabled = false;
      knopf.textContent = knopfText;
    }).catch(function () {
      fehlerZeigen({ _: 'Keine Verbindung zum Server. Bitte prüfe deine Internetverbindung und versuch es noch einmal.' });
      knopf.disabled = false;
      knopf.textContent = knopfText;
    });
  });

  /* ---------- Verfügbarkeitskalender (Gassi) ---------- */
  if (typ !== 'gassi') return;

  var kalender = $('#kalender');
  var kalDaten = null;
  var monat = null;      // erster Tag des angezeigten Monats
  var fokusDatum = null; // Tag mit tabindex=0

  function kalenderLaden() {
    return holeJson('/api/verfuegbarkeit.php').then(function (r) {
      if (!r.ok) throw new Error();
      kalDaten = r.daten;
      var gewaehlt = form.elements.datum.value;
      if (gewaehlt && !kalDaten.tage[gewaehlt]) {
        form.elements.datum.value = '';
        $('#zeitfenster-feld').hidden = true;
      }
      if (!monat) {
        var erster = Object.keys(kalDaten.tage).sort()[0] || kalDaten.von;
        var d = ausIso(erster);
        monat = new Date(d.getFullYear(), d.getMonth(), 1);
      }
      kalenderZeichnen();
      if (form.elements.datum.value) zeitfensterZeigen(form.elements.datum.value);
    }).catch(function () {
      kalender.innerHTML = '<p class="hinweis fehler">Der Kalender konnte nicht geladen werden. Bitte lade die Seite neu.</p>';
    });
  }

  function tagStatus(iso) {
    var t = kalDaten.tage[iso];
    if (!t) return 'zu';
    return t.fenster.some(function (f) { return !f.belegt; }) ? 'frei' : 'voll';
  }

  function kalenderZeichnen(fokusSetzen) {
    var von = ausIso(kalDaten.von), bis = ausIso(kalDaten.bis);
    var minMonat = new Date(von.getFullYear(), von.getMonth(), 1);
    var maxMonat = new Date(bis.getFullYear(), bis.getMonth(), 1);
    var titel = MONATE[monat.getMonth()] + ' ' + monat.getFullYear();
    var heuteIso = isoDatum(new Date());

    var zurueck = el('button', { type: 'button', 'aria-label': 'Vorheriger Monat', text: '‹', disabled: monat <= minMonat });
    var vor = el('button', { type: 'button', 'aria-label': 'Nächster Monat', text: '›', disabled: monat >= maxMonat });
    zurueck.addEventListener('click', function () { monat = new Date(monat.getFullYear(), monat.getMonth() - 1, 1); fokusDatum = null; kalenderZeichnen(); });
    vor.addEventListener('click', function () { monat = new Date(monat.getFullYear(), monat.getMonth() + 1, 1); fokusDatum = null; kalenderZeichnen(); });

    var raster = el('div', { class: 'kalender-raster', role: 'grid', 'aria-labelledby': 'kalender-titel' });
    var kopfzeile = el('div', { role: 'row', style: 'display:contents' });
    WOCHENTAGE_KURZ.forEach(function (w, i) { kopfzeile.appendChild(el('div', { class: 'wt', role: 'columnheader', 'aria-label': WOCHENTAGE[i], text: w })); });
    raster.appendChild(kopfzeile);

    var tageImMonat = new Date(monat.getFullYear(), monat.getMonth() + 1, 0).getDate();
    var versatz = wochentagIndex(monat);
    var buttons = [];
    var gewaehlt = form.elements.datum.value;

    // Fokus-Tag: gewählter Tag, sonst erster freier Tag, sonst erster Tag des Monats
    var monatsTage = [];
    for (var n = 1; n <= tageImMonat; n++) monatsTage.push(isoDatum(new Date(monat.getFullYear(), monat.getMonth(), n)));
    if (!fokusDatum || monatsTage.indexOf(fokusDatum) === -1) {
      fokusDatum = monatsTage.indexOf(gewaehlt) !== -1 ? gewaehlt
        : (monatsTage.filter(function (i) { return tagStatus(i) === 'frei'; })[0] || monatsTage[0]);
    }

    var zeile = el('div', { role: 'row', style: 'display:contents' });
    for (var v = 0; v < versatz; v++) zeile.appendChild(el('div', { class: 'leer', role: 'gridcell' }));
    monatsTage.forEach(function (iso, idx) {
      var status = tagStatus(iso);
      var frei = kalDaten.tage[iso] ? kalDaten.tage[iso].fenster.filter(function (f) { return !f.belegt; }).length : 0;
      var beschreibung = datumLang(iso) + (status === 'frei' ? ', ' + frei + ' Zeitfenster frei' : status === 'voll' ? ', ausgebucht' : ', nicht buchbar');
      var b = el('button', {
        type: 'button',
        class: (status === 'frei' ? 'frei' : status === 'voll' ? 'voll' : '') + (iso === heuteIso ? ' heute' : ''),
        'data-datum': iso,
        'aria-label': beschreibung,
        'aria-pressed': iso === gewaehlt ? 'true' : 'false',
        'aria-disabled': status !== 'frei' ? 'true' : false,
        tabindex: iso === fokusDatum ? '0' : '-1',
        text: String(idx + 1)
      });
      var zelle = el('div', { role: 'gridcell', style: 'display:contents' }, [b]);
      zeile.appendChild(zelle);
      buttons.push(b);
      if ((versatz + idx + 1) % 7 === 0) { raster.appendChild(zeile); zeile = el('div', { role: 'row', style: 'display:contents' }); }
    });
    if (zeile.children.length) raster.appendChild(zeile);

    raster.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-datum]');
      if (!b || b.getAttribute('aria-disabled') === 'true') return;
      tagWaehlen(b.getAttribute('data-datum'));
    });
    raster.addEventListener('keydown', function (ev) {
      var b = ev.target.closest('button[data-datum]');
      if (!b) return;
      var schritte = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
      if (ev.key in schritte) {
        ev.preventDefault();
        var ziel = ausIso(b.getAttribute('data-datum'));
        ziel.setDate(ziel.getDate() + schritte[ev.key]);
        if (ziel < von || ziel > new Date(bis.getFullYear(), bis.getMonth() + 1, 0)) return;
        fokusDatum = isoDatum(ziel);
        if (ziel.getMonth() !== monat.getMonth() || ziel.getFullYear() !== monat.getFullYear()) {
          monat = new Date(ziel.getFullYear(), ziel.getMonth(), 1);
          kalenderZeichnen(true);
        } else {
          buttons.forEach(function (x) { x.tabIndex = x.getAttribute('data-datum') === fokusDatum ? 0 : -1; });
          $('button[data-datum="' + fokusDatum + '"]', raster).focus();
        }
      }
    });

    var hatFreieTage = Object.keys(kalDaten.tage).some(function (i) { return tagStatus(i) === 'frei'; });
    kalender.innerHTML = '';
    kalender.appendChild(el('div', { class: 'kalender-kopf' }, [zurueck, el('h3', { id: 'kalender-titel', 'aria-live': 'polite', text: titel }), vor]));
    kalender.appendChild(raster);
    kalender.appendChild(el('ul', { class: 'kalender-legende' }, [
      el('li', {}, [el('span', { class: 'l-frei' }), 'frei']),
      el('li', {}, [el('span', { class: 'l-voll' }), 'ausgebucht / nicht buchbar']),
      el('li', {}, [el('span', { class: 'l-gewaehlt' }), 'gewählt'])
    ]));
    if (!hatFreieTage) {
      kalender.appendChild(el('p', { class: 'hinweis warnung', text: 'Gerade sind keine Termine frei. Schreib uns gern an asc@steinforth.com oder schau in ein paar Tagen wieder vorbei.' }));
    }
    if (fokusSetzen) $('button[data-datum="' + fokusDatum + '"]', raster).focus();
  }

  function tagWaehlen(iso) {
    var vorher = form.elements.datum.value;
    form.elements.datum.value = iso;
    fokusDatum = iso;
    $$('button[data-datum]', kalender).forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-datum') === iso ? 'true' : 'false'); });
    zeitfensterZeigen(iso, vorher !== iso);
    preisAktualisieren();
  }

  function zeitfensterZeigen(iso, fokus) {
    var feld = $('#zeitfenster-feld');
    var box = $('#zeitfenster');
    var tag = kalDaten.tage[iso];
    var alt = (form.elements.zeitfenster && form.elements.zeitfenster.value) || '';
    $('#gewaehlter-tag').textContent = datumKurz(iso);
    box.innerHTML = '';
    if (!tag) { feld.hidden = true; return; }
    var freie = tag.fenster.filter(function (f) { return !f.belegt; });
    tag.fenster.forEach(function (f) {
      var input = el('input', {
        type: 'radio', name: 'zeitfenster', value: f.zeit, disabled: f.belegt,
        checked: (!f.belegt && (f.zeit === alt || freie.length === 1)) ? true : false
      });
      box.appendChild(el('label', { class: f.belegt ? 'belegt' : '' }, [input, f.zeit.replace('-', '–') + ' Uhr' + (f.belegt ? ' (belegt)' : '')]));
    });
    feld.hidden = false;
    if (fokus && window.matchMedia('(max-width: 899px)').matches) {
      feld.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  kalenderLaden();
})();
