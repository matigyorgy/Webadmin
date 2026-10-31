<div id="server-automation" data-server-id="{$selected.id}" data-csrf="{$csrf}">
<section class="card mb-4" id="scheduler" hidden><div class="card-body"><h3 class="h5">Időzítő</h3><p>Indítás, leállítás, újraindítás vagy biztonsági mentés a megadott időpontban. A feladat bezárt böngésző mellett is lefut, percenkénti pontossággal.</p>
<form class="row g-3" data-schedule-form>
<div class="col-md-6"><label class="form-label w-100">Művelet<select class="form-select" name="operation"><option value="restart">Újraindítás</option><option value="start">Indítás</option><option value="stop">Leállítás</option><option value="backup">Biztonsági mentés</option></select></label></div>
<div class="col-md-6"><label class="form-label w-100">Időpont<input class="form-control" type="time" name="time_of_day" required></label></div>
<div class="col-md-6"><label class="form-label w-100">Ismétlés<select class="form-select" name="repeat"><option value="once">Egyszer</option><option value="daily">Naponta</option><option value="weekly">Hetente</option></select></label></div>
<div class="col-md-6"><label class="form-label w-100">Megőrzött mentések<input class="form-control" type="number" name="keep" min="1" max="100" value="7" required></label></div>
<p class="small mb-0">A megőrzési korlát csak az adott időzítő által készített mentésekre érvényes. A régebbiek automatikusan törlődnek, a kézi mentések megmaradnak.</p>
<div class="col-12"><button class="btn btn-primary" type="submit">Időzítés mentése</button></div></form>
<p class="mt-3" data-automation-message role="status"></p><div class="table-responsive"><table class="table"><thead><tr><th>Művelet</th><th>Következő futás</th><th>Ismétlés</th><th>Utolsó eredmény</th><th></th></tr></thead><tbody data-schedule-rows></tbody></table></div>
</div></section>
<section class="card mb-4" id="backups" hidden><div class="card-body"><h3 class="h5">Biztonsági mentés</h3><p>A teljes szervermappa tömörített mentése. Futó szervernél a fájlmásolat idejére leállítjuk, majd újraindítjuk a játékot; a tömörítés ezután háttérben folytatódik.</p><button class="btn btn-primary" type="button" data-backup-create>Mentés készítése</button> <a class="btn btn-outline-secondary" href="#scheduler">Mentés időzítése</a>
<p class="mt-3" data-automation-message role="status"></p><p class="alert alert-warning">A visszaállítás lecseréli a jelenlegi szerverfájlokat, és csak leállított szerveren, külön megerősítéssel indítható. A szerver a visszaállítás után leállítva marad.</p>
<div class="table-responsive"><table class="table"><thead><tr><th>Mentés ideje</th><th>Tömörített méret</th><th>Visszaállítás</th><th></th></tr></thead><tbody data-backup-rows></tbody></table></div>
</div></section></div>
