// Lokalny edytor slajdów IGAPI — drzewo bloków, właściwości i podgląd renderowany przez silnik GD.
// Ścieżki węzłów mają ten sam format co błędy z ComponentFactory: body.children[0].children[2]

const $ = (id) => document.getElementById(id);

const state = {
    schema: {},          // typ → { container, params[] }
    sources: [],         // [{ id, label, plugin }]
    kind: 'slides',      // 'slides' | 'plugins' — rodzaj otwartego dokumentu
    name: null,          // aktualny slajd albo plugin JSON
    jsonPlugins: [],     // [{ name, label, sources }] z plugins/*.json
    def: null,           // edytowana definicja
    saved: '',           // JSON ostatnio zapisany/wczytany — do wykrywania zmian
    mtime: 0,
    diskMtime: 0,
    cacheKey: null,
    selected: 'slide',
    boxes: [],
    errorPath: null,
    undo: [],
    redo: [],
    lastEditKey: null,
    lastEditAt: 0,
    previewSeq: 0,
    previewTimer: null,
    focusedInput: null,
    sourceData: {},
    tab: 'props',
    openSources: new Set(),   // rozwinięte karty w zakładce Integracje
    fullEntries: {},          // id → pełny wpis z cache (dane + zapytania)
    refreshing: new Set(),
    stories: { entries: [], unplanned: [] },
    storiesMtime: null,
};

const NEW_NODES = {
    Stack: { type: 'Stack', children: [] },
    VerticalSplit: { type: 'VerticalSplit', children: [] },
    Columns: { type: 'Columns', weights: [1, 1], children: [] },
    Text: { type: 'Text', text: 'Nowy tekst' },
    WrappedText: { type: 'WrappedText', text: 'Nowy akapit' },
    Photo: { type: 'Photo', url: '' },
    Separator: { type: 'Separator' },
    Spacer: { type: 'Spacer', height: 20 },
    MoonBar: { type: 'MoonBar', moonPercent: 50, indent: 0 },
    WorldMap: { type: 'WorldMap', markers: [] },
    Table: { type: 'Table', rows: [['1.', 'Wiersz', '42']], align: ['right', 'left', 'right'], flex: 1 },
    Plugin: { type: 'Plugin', source: '' },
};

const MULTILINE = new Set(['text', 'caption', 'description']);

// ---------------------------------------------------------------- API

async function api(path, options = {}) {
    const res = await fetch('/api/' + path, {
        headers: { 'Content-Type': 'application/json' },
        ...options,
        body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || res.statusText);
    return data;
}

// ---------------------------------------------------------------- Ścieżki

function nodeAt(path) {
    if (path === 'slide') return null;
    let node = state.def;
    for (const part of path.split('.')) {
        const m = part.match(/^(\w+)\[(\d+)\]$/);
        node = m ? node[m[1]][+m[2]] : node[part];
        if (node === undefined) return undefined;
    }
    return node;
}

function parentOf(path) {
    const m = path.match(/^(.*)\.children\[(\d+)\]$/);
    return m ? { path: m[1], index: +m[2] } : null;
}

/** Ścieżka węzła po referencji (po przesunięciach indeksy się zmieniają). */
function pathOf(target, node = state.def.body, path = 'body') {
    if (node === target) return path;
    for (const [i, child] of (node.children || []).entries()) {
        const found = pathOf(target, child, `${path}.children[${i}]`);
        if (found) return found;
    }
    return null;
}

// ---------------------------------------------------------------- Zmiany i historia

const snapshot = () => JSON.stringify(state.def);
const isDirty = () => snapshot() !== state.saved;

/** Każda zmiana definicji przechodzi tędy. editKey łączy szybkie pisanie w jednym polu w jeden krok cofania. */
function change(mutate, { editKey = null } = {}) {
    const now = Date.now();
    const coalesce = editKey && editKey === state.lastEditKey && now - state.lastEditAt < 1500;
    if (!coalesce) {
        state.undo.push(snapshot());
        if (state.undo.length > 200) state.undo.shift();
        state.redo = [];
    }
    state.lastEditKey = editKey;
    state.lastEditAt = now;

    mutate();
    renderTree();
    renderDirty();
    schedulePreview();
    publishSelection();
}

function undo() {
    if (!state.undo.length) return;
    state.redo.push(snapshot());
    state.def = JSON.parse(state.undo.pop());
    state.lastEditKey = null;
    keepSelectionValid();
    renderAll();
}

function redo() {
    if (!state.redo.length) return;
    state.undo.push(snapshot());
    state.def = JSON.parse(state.redo.pop());
    state.lastEditKey = null;
    keepSelectionValid();
    renderAll();
}

function keepSelectionValid() {
    if (state.selected !== 'slide' && nodeAt(state.selected) === undefined) state.selected = 'slide';
}

// ---------------------------------------------------------------- Wczytywanie / zapis

const docKey = (kind = state.kind, name = state.name) => `${kind}:${name}`;
const docFile = () => `${state.kind}/${state.name}.json`;

/** „slides:kosmos” / „plugins:asteroids” — także stare zapisy z samą nazwą slajdu. */
function parseDoc(key) {
    const [kind, name] = String(key).includes(':') ? key.split(':') : ['slides', key];
    return { kind, name };
}

async function loadSlides(selectKey) {
    const docs = await fillSlideSelect();
    const stored = localGet('doc') ?? localGet('slide');
    const key = selectKey || (stored && docs.includes(docKey(...Object.values(parseDoc(stored)))) ? stored : docs[0]);
    if (key) await openDoc(key);
}

/** Nazwy slajdów w menu i planie kapitalikami — jak tytuły na slajdach (ŚWIAT, KOSMOS). */
const caps = (text) => String(text ?? '').toLocaleUpperCase('pl-PL');

/** Lista dokumentów w nagłówku: slajdy (● publikowany, ○ wyłączony w planie, — poza planem) i pluginy JSON. */
async function fillSlideSelect() {
    const [slides, stories, plugins] = await Promise.all([api('slides'), api('stories'), api('plugins')]);
    state.stories = stories;
    state.jsonPlugins = plugins;
    const plan = Object.fromEntries(stories.entries.map((e) => [e.id, e]));

    const select = $('slide-select');
    select.innerHTML = '';
    const slideGroup = Object.assign(document.createElement('optgroup'), { label: 'Slajdy' });
    for (const s of slides) {
        const mark = plan[s.name] ? (plan[s.name].enabled ? '●' : '○') : '—';
        slideGroup.append(new Option(`${mark} ${caps(s.label)}  (${s.name}.json)${s.error ? '  ⚠' : ''}`, docKey('slides', s.name)));
    }
    const pluginGroup = Object.assign(document.createElement('optgroup'), { label: 'Pluginy (JSON)' });
    for (const p of plugins) {
        const off = p.enabled === false ? '  — wyłączony' : '';
        pluginGroup.append(new Option(`⧉ ${p.label}  (plugins/${p.name}.json)${off}${p.error ? '  ⚠' : ''}`, docKey('plugins', p.name)));
    }
    select.append(slideGroup, pluginGroup);
    if (state.name) select.value = docKey();
    return [...select.options].map((o) => o.value);
}

/** Zgodność z planem publikacji i starszymi wywołaniami — otwiera slajd po nazwie. */
const openSlide = (name) => openDoc(docKey('slides', name));

async function openDoc(key) {
    const { kind, name } = parseDoc(key);
    const data = await api(`${kind}/${name}`);
    state.kind = kind;
    state.name = name;
    state.def = data.definition;
    state.saved = snapshot();
    state.mtime = data.mtime;
    state.undo = [];
    state.redo = [];
    state.selected = 'slide';
    state.errorPath = null;
    $('slide-select').value = docKey();
    $('banner').hidden = true;
    localSet('doc', docKey());
    await Promise.all([loadSourceData(), loadJsonPluginUsage()]);
    renderAll();
}

async function save() {
    const data = await api(`${state.kind}/${state.name}`, { method: 'PUT', body: { definition: state.def } });
    state.saved = snapshot();
    state.mtime = data.mtime;
    $('banner').hidden = true;
    renderDirty();
    toast('Zapisano ' + docFile());
    if (state.kind === 'plugins') await fillSlideSelect();
}

/** Źródła dokumentu: własne + źródła wstawionych pluginów JSON (slajd ich nie wymienia — ma je z pluginów). */
function docSources() {
    const ids = new Set(state.def.sources || []);
    const walk = (n) => {
        if (n?.plugin) (state.jsonPlugins.find((p) => p.name === n.plugin)?.sources ?? []).forEach((id) => ids.add(id));
        (n?.children || []).forEach(walk);
    };
    walk(state.def.body);
    return [...ids];
}

async function loadSourceData() {
    state.sourceData = {};
    await Promise.all(docSources().map(async (id) => {
        try {
            state.sourceData[id] = await api('sources/' + id);
        } catch (e) {
            state.sourceData[id] = { ok: false, error: e.message };
        }
    }));
}

// ---------------------------------------------------------------- Podgląd

function schedulePreview(delay = 250) {
    clearTimeout(state.previewTimer);
    state.previewTimer = setTimeout(renderPreview, delay);
}

async function renderPreview() {
    const seq = ++state.previewSeq;
    const status = $('status');
    status.className = 'status';
    status.textContent = 'Renderuję…';

    try {
        const data = await api('preview', { method: 'POST', body: { name: state.name, kind: state.kind, definition: state.def } });
        if (seq !== state.previewSeq) return;

        state.normalPreview = null;
        state.previewDoc = docKey();
        $('stage').classList.remove('forced', 'failed');
        $('preview').src = 'data:image/png;base64,' + data.png;
        state.boxes = data.boxes;
        state.errorPath = null;
        status.textContent = data.skipped
            ? `Dziś pominięty: ${data.skipped} · render ${data.ms} ms`
            : `Render ${data.ms} ms · dane z cache`;
        if (state.kind === 'plugins' && state.def.enabled === false) status.textContent += ' · plugin wyłączony — na slajdach się nie losuje';
        renderBoxes();
    } catch (e) {
        if (seq !== state.previewSeq) return;
        status.className = 'status error';
        status.textContent = e.message;
        // Stary obraz zostaje przygaszony, a z innego dokumentu (po przełączeniu) znika, żeby nie mylił
        $('stage').classList.add('failed');
        if (state.previewDoc !== docKey()) {
            $('preview').removeAttribute('src');
            state.boxes = [];
            renderBoxes();
        }
        const m = e.message.match(/^(body(?:\.children\[\d+\])*)/);
        state.errorPath = m ? m[1] : null;
        if (state.errorPath) {
            const btn = document.createElement('button');
            btn.textContent = 'Pokaż blok';
            btn.onclick = () => select(state.errorPath);
            status.append(btn);
        }
    }
    renderTree();
}

// ---------------------------------------------------------------- Kalendarz puli

const hasPool = (n) => !!n && (!!n.pick || (n.children || []).some(hasPool));

/** Co pule slajdu wylosują w najbliższych 7 dniach — liczone w routerze z niezapisanej definicji. */
function calendarField() {
    const wrap = document.createElement('div');
    wrap.className = 'calendar';
    wrap.append(sub('Kalendarz puli — 7 dni'));
    const body = document.createElement('div');
    body.append(note('Liczę losowanie…'));
    wrap.append(body);

    const key = JSON.stringify(state.def);
    if (state.calendar?.key !== key) {
        state.calendar = { key, request: api('calendar', { method: 'POST', body: { name: state.name, definition: state.def } }) };
    }
    state.calendar.request
        .then((data) => { if (state.calendar?.key === key) body.replaceChildren(calendarTable(data)); })
        .catch((e) => body.replaceChildren(note('Kalendarz: ' + e.message)));
    return wrap;
}

function calendarTable({ days, sizes }) {
    const frag = document.createDocumentFragment();
    const pools = [...new Set(Object.values(days).flatMap(Object.keys))];
    const today = Object.keys(days)[0];
    for (const pool of pools) {
        const node = nodeAt(pool);
        if (pools.length > 1) frag.append(note(node?.name ?? pool));
        const table = document.createElement('table');
        for (const [date, picked] of Object.entries(days)) {
            const tr = table.insertRow();
            tr.className = date === today ? 'today' : '';
            const day = new Date(`${date}T12:00:00`);
            tr.insertCell().textContent = date === today ? 'dziś'
                : day.toLocaleDateString('pl-PL', { weekday: 'short', day: 'numeric', month: 'numeric' });
            const cell = tr.insertCell();
            let quarters = 0, known = true;
            for (const id of picked[pool] ?? []) {
                const k = /^\d+$/.test(id) ? +id : (node?.children ?? []).findIndex((c) => c.type === 'Plugin' && c.plugin === id);
                const child = node?.children?.[k];
                // Jak staticSize() w ComponentFactory: "quarters" węzła, "size" bloku Plugin, rozmiar pluginu JSON, Plugin PHP = 2/4
                const size = child?.quarters ?? (child?.type === 'Plugin' ? (Number(child.size) || sizes[id] || 2) : null);
                if (size == null) known = false; else quarters += size;
                const maybe = child?.type === 'Plugin' && nodeSkip(child)?.can;
                const label = child?.plugin ? (state.jsonPlugins.find((p) => p.name === child.plugin)?.label ?? id) : child?.name ?? child?.source ?? child?.type ?? id;
                const c = chip(label + (size ? ` ${size}/4` : ''), 'pick-chip' + (maybe ? ' maybe' : ''),
                    maybe ? 'Może pominąć — plugin bywa pomijany (skip()); tego dnia pula dobierze wtedy inny blok' : '');
                if (k >= 0) c.onclick = () => select(`${pool}.children[${k}]`);
                cell.append(c);
            }
            tr.insertCell().textContent = known ? `${quarters}/4` : '';
        }
        frag.append(table);
    }
    frag.append(note('Dni po dzisiejszym liczone na dzisiejszych danych — przerywana ramka = plugin może tego dnia wypaść. Kliknięcie zaznacza blok.'));
    return frag;
}

// ---------------------------------------------------------------- Podgląd bloku, którego dzienny pick nie wylosował

/** Czy węzeł (albo jego przodek) jest dzieckiem kontenera z pick i dziś nie trafił do slajdu. */
function isPickedOut(path) {
    if (!state.boxes.length) return false;
    const drawn = new Set(state.boxes.map((b) => b.path));
    const re = /\.children\[\d+\]/g;
    let m;
    while ((m = re.exec(path))) {
        const parentPath = path.slice(0, m.index);
        const childPath = path.slice(0, m.index + m[0].length);
        if (nodeAt(parentPath)?.pick && !drawn.has(childPath)) {
            // Plugin pominięty dziś przez skip() nie ma czego narysować — wymuszony podgląd byłby pusty
            const node = nodeAt(path);
            return !(node?.type === 'Plugin' && nodeSkip(node)?.today !== undefined);
        }
    }
    return false;
}

async function showForcedPreview(path) {
    clearTimeout(state.forcedTimer);
    state.forcedPath = path;
    // Krótka zwłoka — przejechanie kursorem po drzewie nie odpala renderu każdego wiersza
    state.forcedTimer = setTimeout(async () => {
        const key = path + '|' + snapshot();
        state.forcedCache ??= new Map();
        $('stage').classList.add('forced-loading');
        try {
            if (!state.forcedCache.has(key)) {
                state.forcedCache.set(key, await api('preview', { method: 'POST', body: { name: state.name, kind: state.kind, definition: state.def, force: path } }));
            }
            if (state.forcedPath !== path) return;
            const data = state.forcedCache.get(key);
            state.normalPreview ??= { src: $('preview').src, boxes: state.boxes };
            $('preview').src = 'data:image/png;base64,' + data.png;
            state.boxes = data.boxes;
            $('stage').classList.add('forced');
            $('stage').dataset.label = 'Gdy wylosuje się: ' + summary(nodeAt(path)).text;
            renderBoxes(path);
        } catch (e) {
            state.forcedCache.delete(key);
            toast('Nie udało się wyrenderować: ' + e.message);
        } finally {
            $('stage').classList.remove('forced-loading');
        }
    }, 150);
}

function hideForcedPreview() {
    clearTimeout(state.forcedTimer);
    state.forcedPath = null;
    $('stage').classList.remove('forced', 'forced-loading');
    if (!state.normalPreview) return renderBoxes();
    $('preview').src = state.normalPreview.src;
    state.boxes = state.normalPreview.boxes;
    state.normalPreview = null;
    renderBoxes();
}

function renderBoxes(hoverPath = null) {
    const overlay = $('overlay');
    const scale = overlay.clientWidth / 1080;
    overlay.innerHTML = '';

    for (const box of state.boxes) {
        const isSelected = box.path === state.selected;
        const isHover = box.path === hoverPath;
        if (!isSelected && !isHover) continue;

        const el = document.createElement('div');
        el.className = 'box' + (isSelected ? ' selected' : '') + (isHover ? ' hover' : '');
        Object.assign(el.style, {
            left: box.x * scale + 'px',
            top: box.y * scale + 'px',
            width: box.w * scale + 'px',
            height: Math.max(box.h, 2) * scale + 'px',
        });
        overlay.append(el);
    }
}

/** Najmniejszy blok pod kursorem = najgłębszy węzeł. */
function boxAt(event) {
    const overlay = $('overlay');
    const rect = overlay.getBoundingClientRect();
    const scale = overlay.clientWidth / 1080;
    const x = (event.clientX - rect.left) / scale;
    const y = (event.clientY - rect.top) / scale;

    let best = null;
    for (const b of state.boxes) {
        if (b.h <= 0 || x < b.x || x > b.x + b.w || y < b.y || y > b.y + b.h) continue;
        if (!best || b.w * b.h < best.w * best.h) best = b;
    }
    return best;
}

// ---------------------------------------------------------------- Drzewo

function summary(node) {
    if (node.name) return { text: node.name, named: true };
    // Jak przy pluginach PHP: identyfikator na szaro (nazwa bloku — tylko jeśli nadana w polu name)
    if (node.plugin) return { text: node.plugin, named: false };
    const s = node.text ?? node.source ?? node.url ?? (node.height !== undefined ? node.height + ' px' : '');
    return { text: String(s), named: false };
}

function renderTree() {
    const tree = $('tree');
    tree.innerHTML = '';

    const root = document.createElement('li');
    root.append(state.kind === 'plugins'
        ? row('slide', { type: 'Plugin JSON' }, state.def.label ?? state.name)
        : row('slide', { type: 'Slajd' }, `${state.def.title ?? ''}`));
    tree.append(root);

    const list = document.createElement('ul');
    list.append(treeItem(state.def.body, 'body'));
    root.append(list);
}

function treeItem(node, path) {
    const li = document.createElement('li');
    li.append(row(path, node));

    if (node.children?.length) {
        const ul = document.createElement('ul');
        node.children.forEach((child, i) => ul.append(treeItem(child, `${path}.children[${i}]`)));
        li.append(ul);
    }
    return li;
}

function row(path, node, label = null) {
    const el = document.createElement('div');
    el.className = 'row' + (path === state.selected ? ' selected' : '') + (path === state.errorPath ? ' error' : '');
    el.dataset.path = path;

    const type = document.createElement('span');
    const isContainer = state.schema[node.type]?.container;
    type.className = 'type' + (isContainer ? ' container' : '') + (node.type === 'Plugin' ? ' plugin' : '');
    type.textContent = node.type;

    const sum = document.createElement('span');
    const s = label !== null ? { text: label, named: true } : summary(node);
    sum.className = 'summary' + (s.named ? ' named' : '');
    sum.textContent = s.text;
    el.append(type, sum);

    if (node.when) el.append(chip('if ' + node.when));
    if (node.plugin) el.append(chip('json', 'chip-json', `${jsonPluginLabel(node.plugin)} — kliknij dwukrotnie, żeby edytować`));
    const off = node.plugin && !jsonPluginEnabled(node.plugin);
    const skip = node.type === 'Plugin' ? nodeSkip(node) : null;
    if (off) {
        el.append(chip('wyłączony', 'chip-off', 'Plugin jest wyłączony (enabled: false w plugins/' + node.plugin + '.json)'
            + '\nNie rysuje się, nie losuje w pick i nie pobiera danych. Włączysz go we właściwościach bloku.'));
    } else if (skip?.today !== undefined) {
        el.append(chip('dziś pominięty', 'chip-skip-today', 'Plugin nie ma dziś danych: ' + skip.today
            + '\nW kontenerze z pick w jego miejsce losuje się inny blok.'));
    } else if (skip?.can) {
        el.append(chip('może pominąć', 'chip-skip', 'Plugin bywa pomijany, gdy danego dnia nie ma danych'
            + '\n— w kontenerze z pick w jego miejsce losuje się wtedy inny blok.'));
    }
    if (node.order) el.append(chip(node.order));
    if (node.pick) el.append(chip('pick ' + node.pick));
    const pickedOut = isPickedOut(path);
    if (off) {
        el.classList.add('skipped');
        el.title = 'Plugin wyłączony — nie losuje się';
    } else if (path.startsWith('body') && state.boxes.length && !state.boxes.some((b) => b.path === path)) {
        el.classList.add('skipped');
        el.title = pickedOut
            ? 'Dziś niewylosowany (pick) — najedź, żeby zobaczyć, jak będzie wyglądał'
            : 'Nie ma go w dzisiejszym podglądzie (when, skip pluginu albo brak danych)';
    }
    if (pickedOut) el.classList.add('pickable');

    el.onclick = () => select(path);
    if (node.plugin) el.ondblclick = () => editJsonPlugin(node.plugin);
    el.onmouseenter = () => (pickedOut ? showForcedPreview(path) : renderBoxes(path));
    el.onmouseleave = () => (pickedOut ? hideForcedPreview() : renderBoxes());

    if (path !== 'slide' && path !== 'body') makeDraggable(el, path);
    if (path !== 'slide') makeDropTarget(el, path);
    return el;
}

function chip(text, className = '', title = '') {
    const c = document.createElement('span');
    c.className = 'chip ' + className;
    c.textContent = text;
    if (title) c.title = title;
    return c;
}

function jsonPluginLabel(id) {
    const p = state.jsonPlugins.find((x) => x.name === id);
    return p ? `${p.label} (plugins/${id}.json)` : `plugins/${id}.json — brak pliku`;
}

const jsonPluginEnabled = (id) => state.jsonPlugins.find((x) => x.name === id)?.enabled !== false;

/** enabled: false zaraz za label (czytelniej w pliku); włączony plugin nie ma tego pola wcale. */
function withEnabled(def, on) {
    const { label, enabled, ...rest } = def;
    return on ? { ...(label !== undefined ? { label } : {}), ...rest } : { ...(label !== undefined ? { label } : {}), enabled: false, ...rest };
}

/** Włącza/wyłącza plugin JSON z poziomu slajdu — zapisuje od razu plugins/<id>.json, slajd zostaje bez zmian. */
async function setJsonPluginEnabled(id, on) {
    const data = await api('plugins/' + id);
    await api('plugins/' + id, { method: 'PUT', body: { definition: withEnabled(data.definition, on) } });
    toast(`Plugin ${id} ${on ? 'włączony' : 'wyłączony'} (plugins/${id}.json)`);
    await fillSlideSelect();
    renderAll();
    schedulePreview(0);
}

async function editJsonPlugin(id) {
    if (isDirty() && !confirm('Masz niezapisane zmiany. Przejść do pluginu i je porzucić?')) return;
    await openDoc(docKey('plugins', id));
}

/** Czy plugin bywa pomijany (canSkip) i czy dziś zwrócił skip() — { can, today: powód | undefined }. */
/** Pominięcie dla bloku Plugin: PHP — po jego źródle, JSON — po źródłach z plugins/<id>.json. */
function nodeSkip(node) {
    if (!node.plugin) return pluginSkip(node.source);
    const ids = state.jsonPlugins.find((p) => p.name === node.plugin)?.sources ?? [];
    const all = ids.map(pluginSkip).filter(Boolean);
    if (!all.length) return null;
    return { can: all.some((x) => x.can), today: all.find((x) => x.today !== undefined)?.today };
}

function pluginSkip(id) {
    const can = state.sources.find((s) => s.id === id)?.canSkip ?? false;
    const today = state.sourceData[id]?.data?._skip;
    return can || today !== undefined ? { can, today } : null;
}

function select(path) {
    state.selected = path;
    renderTree();
    renderProps();
    renderBoxes();
    publishSelection();
    document.querySelector('.row.selected')?.scrollIntoView({ block: 'nearest' });
}

/** Zaznaczenie → .igapi/selection.json, żeby Claude Code w terminalu wiedział, o który blok chodzi. */
function publishSelection() {
    clearTimeout(state.selectionTimer);
    state.selectionTimer = setTimeout(() => {
        const node = state.selected === 'slide' ? null : nodeAt(state.selected);
        api('selection', {
            method: 'POST',
            body: {
                slide: state.name,
                kind: state.kind,
                file: docFile(),
                path: state.selected === 'slide' ? null : state.selected,
                node: node ? { ...node, children: node.children ? `[${node.children.length} dzieci]` : undefined } : null,
                unsavedChanges: isDirty(),
                previewError: $('status').classList.contains('error') ? $('status').firstChild?.textContent ?? null : null,
                sources: docSources(),
            },
        }).catch(() => {});
    }, 400);
}

// --- Przeciąganie: górna połowa wiersza = przed, dolna = za; dolna połowa kontenera = do środka (na koniec)

let dragged = null;

function makeDraggable(el, path) {
    el.draggable = true;
    el.ondragstart = (e) => {
        dragged = nodeAt(path);
        e.dataTransfer.effectAllowed = 'move';
        e.stopPropagation();
    };
    el.ondragend = () => { dragged = null; };
}

function dropPosition(el, e, path) {
    const rect = el.getBoundingClientRect();
    const lower = e.clientY > rect.top + rect.height / 2;
    if (path === 'body') return 'inside';
    if (lower && state.schema[nodeAt(path).type]?.container) return 'inside';
    return lower ? 'after' : 'before';
}

function makeDropTarget(el, path) {
    el.ondragover = (e) => {
        if (!dragged) return;
        const targetPath = path === 'slide' ? 'body' : path;
        const draggedPath = pathOf(dragged);
        if (targetPath === draggedPath || targetPath.startsWith(draggedPath + '.')) return;
        e.preventDefault();
        const pos = dropPosition(el, e, targetPath);
        el.classList.remove('drop-before', 'drop-after', 'drop-inside');
        el.classList.add('drop-' + pos);
    };
    el.ondragleave = () => el.classList.remove('drop-before', 'drop-after', 'drop-inside');
    el.ondrop = (e) => {
        e.preventDefault();
        el.classList.remove('drop-before', 'drop-after', 'drop-inside');
        const targetPath = path === 'slide' ? 'body' : path;
        const pos = dropPosition(el, e, targetPath);
        const target = nodeAt(targetPath);
        const node = dragged;
        dragged = null;
        if (!node) return;

        change(() => {
            const from = parentOf(pathOf(node));
            nodeAt(from.path).children.splice(from.index, 1);

            if (pos === 'inside') {
                (target.children ||= []).push(node);
            } else {
                const to = parentOf(pathOf(target));
                const parent = nodeAt(to.path);
                parent.children.splice(to.index + (pos === 'after' ? 1 : 0), 0, node);
            }
        });
        select(pathOf(node));
    };
}

// --- Przyciski drzewa

function addNode() {
    const type = $('add-type').value;
    const node = structuredClone(NEW_NODES[type] || { type });
    if (type === 'Plugin') {
        const json = state.jsonPlugins.find((p) => p.name !== state.name);
        if (json) {
            node.plugin = json.name;
            delete node.source;
        } else {
            node.source = state.sources.find((s) => s.plugin)?.id ?? '';
        }
    }

    const sel = state.selected === 'slide' ? 'body' : state.selected;
    const selNode = nodeAt(sel);

    change(() => {
        if (state.schema[selNode.type]?.container) {
            (selNode.children ||= []).push(node);
        } else {
            const p = parentOf(sel);
            nodeAt(p.path).children.splice(p.index + 1, 0, node);
        }
    });
    select(pathOf(node));
}

function moveNode(delta) {
    const p = parentOf(state.selected);
    if (!p) return;
    const siblings = nodeAt(p.path).children;
    const to = p.index + delta;
    if (to < 0 || to >= siblings.length) return;

    const node = siblings[p.index];
    change(() => {
        siblings.splice(p.index, 1);
        siblings.splice(to, 0, node);
    });
    select(pathOf(node));
}

function duplicateNode() {
    const p = parentOf(state.selected);
    if (!p) return;
    const copy = structuredClone(nodeAt(state.selected));
    change(() => nodeAt(p.path).children.splice(p.index + 1, 0, copy));
    select(pathOf(copy));
}

function removeNode() {
    const p = parentOf(state.selected);
    if (!p) return;
    change(() => nodeAt(p.path).children.splice(p.index, 1));
    const siblings = nodeAt(p.path).children;
    select(siblings.length ? `${p.path}.children[${Math.min(p.index, siblings.length - 1)}]` : p.path);
}

// ---------------------------------------------------------------- Właściwości

function renderProps() {
    const props = $('props');
    props.innerHTML = '';

    if (state.selected === 'slide' && state.kind === 'plugins') {
        props.append(heading('Plugin JSON: plugins/' + state.name + '.json'));
        props.append(textField(state.def, 'label', { hint: 'nazwa w edytorze i tytuł podglądu' }));
        props.append(enabledField());
        props.append(sizeField());
        props.append(sourcesField());
        props.append(note(`W slajdzie: blok Plugin → plugin: ${state.name}. Rozmiar: ${sizeLabel(state.def)} wysokości slajdu.`));
        const used = usedInSlides(state.name);
        props.append(note(used.length ? 'Używany w: ' + used.join(', ') : 'Nie jest jeszcze używany w żadnym slajdzie.'));
        renderData();
        return;
    }

    if (state.selected === 'slide') {
        props.append(heading('Slajd: ' + state.name + '.json'));
        for (const key of ['label', 'title', 'subtitle', 'footer']) {
            const hint = { label: 'nazwa w logach', footer: '{{credits}} = źródła dzisiejszych bloków' }[key] ?? '';
            props.append(textField(state.def, key, { hint }));
        }
        if (hasPool(state.def.body)) props.append(calendarField());
        props.append(sourcesField());
        renderData();
        return;
    }

    const node = nodeAt(state.selected);
    if (!node) return;
    const schema = state.schema[node.type];

    props.append(heading(node.type));
    if (node.type === 'Plugin' && node.plugin) {
        const edit = Object.assign(document.createElement('button'), { textContent: 'Edytuj plugin ' + node.plugin });
        edit.onclick = () => editJsonPlugin(node.plugin);
        const on = jsonPluginEnabled(node.plugin);
        const toggle = Object.assign(document.createElement('button'), {
            textContent: on ? 'Wyłącz plugin' : 'Włącz plugin',
            title: on ? 'Wyłącza plugin wszędzie — przestaje się losować na wszystkich slajdach (zapis od razu)' : 'Plugin znowu będzie się rysował i losował',
        });
        toggle.onclick = () => setJsonPluginEnabled(node.plugin, !on);
        const buttons = Object.assign(document.createElement('div'), { className: 'buttons' });
        buttons.append(edit, toggle);
        props.append(buttons);
        if (!on) props.append(note('Plugin jest wyłączony: nie rysuje się, nie losuje w pick i nie pobiera danych.'));
    }
    props.append(textField(node, 'name', { hint: 'nazwa bloku w drzewie' }));
    props.append(textField(node, 'when', { hint: 'pomiń, gdy brak danych', list: 'data-paths' }));
    const parent = state.selected.match(/^(.*)\.children\[\d+\]$/)?.[1];
    if (parent && nodeAt(parent)?.type === 'VerticalSplit') props.append(heightField(node));
    if (parent && nodeAt(parent)?.pick) {
        props.append(textField(node, 'weight', { numeric: true, hint: 'szansa w losowaniu (domyślnie 1, 2 = dwa razy częściej); wczoraj wylosowany ×0,15, przedwczoraj ×0,4' }));
    }
    if (schema?.container) {
        props.append(selectField(node, 'order', ['', 'daily'], 'daily = kolejność losowana raz dziennie'));
        // Na slajdzie pula ma budżet ćwiartek: po N-tym losuje dalej, dopóki zostało wolne miejsce
        const of = node.children?.length ?? 0;
        props.append(textField(node, 'pick', { numeric: true, hint: state.kind === 'slides'
            ? `losuj min. tyle (z ${of}) — pula dobiera kolejne, aż wypełni wolne ćwiartki`
            : `ile dzieci losować dziennie (z ${of})` }));
    }

    if (!schema) {
        props.append(note(`Nieznany typ „${node.type}”`));
    } else {
        props.append(sub('Parametry'));
        for (const param of schema.params) props.append(paramField(node, param));
    }
    if (schema?.params.some((p) => p.name === 'text')) props.append(variantsField(node));
    if (state.kind === 'slides') props.append(transferField(node));
    renderData();
}

// --- Teksty zależne od wartości (variants → {{variant}})

/** Wartość ze ścieżki w danych źródeł wczytanych dla dokumentu (np. weather.medianTemp). */
function dataValue(path) {
    const [id, ...rest] = String(path || '').split('.');
    let v = state.sourceData[id]?.data;
    for (const key of rest) v = v?.[key];
    return v;
}

/** Zakres, z którego dziś bierze się tekst — ta sama reguła co ComponentFactory::variant(). */
function activeRange(variants) {
    const value = Number(dataValue(variants.by));
    const known = dataValue(variants.by) !== undefined && dataValue(variants.by) !== null && !isNaN(value);
    let best = null;
    for (const r of variants.ranges || []) {
        if (r.from === undefined || r.from === null || r.from === '') { best ??= r; continue; }
        if (known && value >= r.from && (best === null || best.from === undefined || best.from === null || r.from > best.from)) best = r;
    }
    return best;
}

/** Zakresy od najwyższego „od”; zakres bez „od” (poniżej wszystkich) zawsze na końcu. */
function sortRanges(ranges) {
    const has = (r) => r.from !== undefined && r.from !== null && r.from !== '';
    ranges.sort((a, b) => (has(a) && has(b) ? b.from - a.from : has(a) ? -1 : has(b) ? 1 : 0));
}

const textCount = (n) => `${n} ${n === 1 ? 'tekst' : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'teksty' : 'tekstów'}`;

function variantsField(node) {
    const wrap = document.createElement('div');
    wrap.className = 'variants';
    wrap.append(sub('Teksty zależne od wartości'));

    if (!node.variants) {
        wrap.append(note('Np. podpis pogody zależny od temperatury: zakresy „od” z zestawem tekstów, z którego codziennie losuje się jeden. W tekście bloku wstaw {{variant}}.'));
        const add = Object.assign(document.createElement('button'), { textContent: 'Dodaj teksty zależne od wartości' });
        add.onclick = () => {
            change(() => {
                node.variants = { by: '', ranges: [{ from: 0, texts: [''] }, { texts: [''] }] };
                if (typeof node.text === 'string' && !node.text.includes('{{variant}}')) node.text = '{{variant}}' + node.text;
            });
            renderProps();
        };
        wrap.append(add);
        return wrap;
    }

    const v = node.variants;
    v.ranges ||= [];
    wrap.append(textField(v, 'by', { hint: 'liczba z danych, np. weather.medianTemp', list: 'data-paths' }));
    const value = dataValue(v.by);
    const active = activeRange(v);
    if (!String(node.text ?? '').includes('{{variant}}')) wrap.append(note('Uwaga: tekst bloku nie zawiera {{variant}} — wylosowany tekst nigdzie się nie pojawi.'));
    wrap.append(note(value === undefined ? 'Brak tej wartości w danych — użyty będzie zakres „poniżej”.' : `Dziś: ${typeof value === 'number' ? value.toLocaleString('pl-PL') : value} → podświetlony zakres. Jeden tekst w linii; losowany raz dziennie.`));

    const edit = (mutate, key) => change(mutate, { editKey: state.selected + ':variants:' + key });
    v.ranges.forEach((range, i) => {
        const row = document.createElement('div');
        row.className = 'variant-range' + (range === active ? ' active' : '');
        const isRest = range.from === undefined || range.from === null || range.from === '';

        const head = document.createElement('div');
        head.className = 'variant-head';
        const from = Object.assign(document.createElement('input'), { type: 'number', step: 'any', value: isRest ? '' : range.from, placeholder: 'poniżej' });
        from.title = 'Zakres obejmuje wartości od tej liczby wzwyż (do „od” wyższego zakresu). Puste = poniżej wszystkich.';
        from.oninput = () => edit(() => {
            if (from.value.trim() === '') delete range.from; else range.from = Number(from.value);
        }, i + ':from');
        from.onchange = () => { change(() => sortRanges(v.ranges)); renderProps(); };
        const label = Object.assign(document.createElement('span'), { textContent: 'od' });
        const count = Object.assign(document.createElement('span'), { className: 'hint', textContent: textCount((range.texts || []).filter((t) => t.trim()).length) });
        const del = Object.assign(document.createElement('button'), { textContent: '✕', title: 'Usuń zakres' });
        del.onclick = () => { change(() => v.ranges.splice(i, 1)); renderProps(); };
        head.append(label, from, count, del);

        const texts = Object.assign(document.createElement('textarea'), { value: (range.texts || []).join('\n'), rows: Math.max(2, (range.texts || []).length) });
        texts.placeholder = 'jeden tekst w linii';
        texts.oninput = () => edit(() => { range.texts = texts.value.split('\n'); }, i + ':texts');
        texts.onchange = () => { change(() => { range.texts = range.texts.map((t) => t.trim()).filter(Boolean); }); renderProps(); };
        texts.onfocus = () => { state.focusedInput = texts; };
        row.append(head, texts);
        wrap.append(row);
    });

    const buttons = Object.assign(document.createElement('div'), { className: 'buttons' });
    const addRange = Object.assign(document.createElement('button'), { textContent: 'Dodaj zakres' });
    addRange.onclick = () => {
        const top = Math.max(0, ...v.ranges.map((r) => Number(r.from)).filter((n) => !isNaN(n)));
        change(() => { v.ranges.push({ from: top + 5, texts: [''] }); sortRanges(v.ranges); });
        renderProps();
    };
    const remove = Object.assign(document.createElement('button'), { textContent: 'Usuń teksty zależne', title: 'Usuwa zakresy — {{variant}} w tekście zostanie pusty' });
    remove.onclick = () => { change(() => { delete node.variants; }); renderProps(); };
    buttons.append(addRange, remove);
    wrap.append(buttons);
    return wrap;
}

// --- Przenoszenie bloku do innego slajdu

const blocks = (n) => `${n} ${n === 1 ? 'blok' : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'bloki' : 'bloków'}`;

/** Kontenery wszystkich innych slajdów: „KOSMOS › VerticalSplit › Pula (pick 1, 4 bloki)” → { slide, path }. */
async function transferTargets() {
    const slides = (await api('slides')).filter((s) => s.name !== state.name && !s.error);
    const targets = [];
    await Promise.all(slides.map(async (s) => {
        const def = (await api('slides/' + s.name)).definition;
        const walk = (n, path, label) => {
            if (!state.schema[n?.type]?.container) return;
            const extra = [n.pick ? 'pick ' + n.pick : '', blocks(n.children?.length ?? 0)].filter(Boolean).join(', ');
            targets.push({ slide: s.name, path, label: `${caps(s.label)} › ${label} (${extra})` });
            (n.children || []).forEach((c, i) => walk(c, `${path}.children[${i}]`, `${label} › ${c.name ?? c.type}`));
        };
        walk(def.body, 'body', def.body?.name ?? def.body?.type ?? 'body');
    }));
    return targets.sort((a, b) => a.label.localeCompare(b.label, 'pl'));
}

/** Pole „Przenieś do slajdu”: wybór kontenera w innym slajdzie + Przenieś / Kopiuj (zapis obu plików od razu). */
function transferField(node) {
    const wrap = field('Przenieś do slajdu', 'na koniec wybranego kontenera');
    const sel = document.createElement('select');
    sel.add(new Option('wczytuję slajdy…', ''));
    sel.disabled = true;
    const moveBtn = Object.assign(document.createElement('button'), { textContent: 'Przenieś', disabled: true, title: 'Usuwa blok z tego slajdu i dopisuje do wybranego — zapisuje oba pliki' });
    const copyBtn = Object.assign(document.createElement('button'), { textContent: 'Kopiuj', disabled: true, title: 'Dopisuje kopię bloku do wybranego slajdu — ten slajd zostaje bez zmian' });
    const buttons = Object.assign(document.createElement('div'), { className: 'buttons' });
    buttons.append(moveBtn, copyBtn);
    wrap.append(sel, buttons);

    const selectedPath = state.selected;
    transferTargets().then((targets) => {
        if (state.selected !== selectedPath) return;
        sel.innerHTML = '';
        if (!targets.length) {
            sel.add(new Option('brak innych slajdów', ''));
            return;
        }
        targets.forEach((t, i) => sel.add(new Option(t.label, String(i))));
        sel.disabled = moveBtn.disabled = copyBtn.disabled = false;
        moveBtn.onclick = () => transferNode(node, targets[+sel.value], true);
        copyBtn.onclick = () => transferNode(node, targets[+sel.value], false);
    }).catch((e) => {
        sel.innerHTML = '';
        sel.add(new Option('błąd: ' + e.message, ''));
    });
    return wrap;
}

async function transferNode(node, target, removeHere) {
    if (!target) return;
    const path = pathOf(node);
    if (removeHere && path === 'body') return toast('Całego body nie da się przenieść');
    if (removeHere && isDirty() && !confirm('Ten slajd ma niezapisane zmiany — zapiszą się razem z przeniesieniem. Kontynuować?')) return;
    try {
        const data = await api('slides/' + target.slide);
        const def = data.definition;
        let container = def;
        for (const part of target.path.split('.')) {
            const m = part.match(/^(\w+)\[(\d+)\]$/);
            container = m ? container[m[1]][+m[2]] : container[part];
        }
        (container.children ||= []).push(structuredClone(node));
        await api('slides/' + target.slide, { method: 'PUT', body: { definition: def } });

        if (removeHere) {
            const p = parentOf(path);
            change(() => nodeAt(p.path).children.splice(p.index, 1));
            await save();
            select(p.path);
        }
        await loadJsonPluginUsage();
        const what = node.plugin ?? node.name ?? node.type;
        const fixed = !container.pick && container.type !== 'Stack' ? ' — kontener bez pick: blok dochodzi jako kolejna część' : '';
        toast(`${removeHere ? 'Przeniesiono' : 'Skopiowano'} ${what} → ${target.label.split(' (')[0]}${fixed}`);
    } catch (e) {
        toast('Nie udało się: ' + e.message);
    }
}

function paramField(node, param) {
    const { name, type } = param;
    if (type === 'font') return selectField(node, name, ['', 'regular', 'bold'], defaultHint(param), param.required);
    if (type === 'source') {
        const plugins = state.sources.filter((s) => s.plugin).map((s) => s.id);
        return selectField(node, name, ['', ...plugins], 'plugin w PHP (gdy nie wybrano pluginu JSON)', param.required);
    }
    if (type === 'jsonPlugin') {
        const ids = state.jsonPlugins.map((p) => p.name).filter((id) => !(state.kind === 'plugins' && id === state.name));
        return selectField(node, name, ['', ...ids], 'plugin JSON z plugins/ — ma pierwszeństwo', param.required);
    }
    if (name === 'align') return selectField(node, name, ['', 'left', 'center', 'right'], defaultHint(param));
    if (type.includes('bool')) return checkField(node, param);
    if (/color/i.test(name) || /^#[0-9a-f]{6}$/i.test(param.default ?? '')) return colorField(node, param);
    if (type.includes('array')) return listField(node, param);
    return textField(node, name, {
        hint: [type, defaultHint(param)].filter(Boolean).join(' · '),
        placeholder: param.default ?? '',
        multiline: MULTILINE.has(name),
        numeric: /int|float/.test(type),
        required: param.required,
    });
}

const defaultHint = (p) => (p.default !== null && p.default !== undefined && p.default !== '' ? 'domyślnie ' + p.default : '');

function setProp(obj, key, value) {
    change(() => {
        if (value === '' || value === null || value === undefined) delete obj[key];
        else obj[key] = value;
    }, { editKey: state.selected + ':' + key });
}

function field(label, hint, required = false) {
    const wrap = document.createElement('div');
    wrap.className = 'field' + (required ? ' required' : '');
    const l = document.createElement('label');
    const a = document.createElement('span');
    a.textContent = label;
    const b = document.createElement('span');
    b.className = 'hint';
    b.textContent = hint || '';
    l.append(a, b);
    wrap.append(l);
    return wrap;
}

function textField(obj, key, { hint = '', placeholder = '', multiline = false, numeric = false, required = false, list = null } = {}) {
    const wrap = field(key, hint, required);
    const input = document.createElement(multiline ? 'textarea' : 'input');
    input.value = obj[key] ?? '';
    input.placeholder = placeholder;
    if (list) input.setAttribute('list', list);
    input.oninput = () => {
        const raw = input.value;
        // Liczby zapisujemy jako liczby; placeholder {{…}} w polu liczbowym zostaje tekstem
        const value = numeric && raw.trim() !== '' && !isNaN(Number(raw)) ? Number(raw) : raw;
        setProp(obj, key, value);
    };
    input.onfocus = () => { state.focusedInput = input; };
    wrap.append(input);
    return wrap;
}

function selectField(obj, key, options, hint = '', required = false) {
    const wrap = field(key, hint, required);
    const sel = document.createElement('select');
    for (const o of options) sel.add(new Option(o === '' ? '— domyślnie —' : o, o));
    if (obj[key] !== undefined && !options.includes(obj[key])) sel.add(new Option(obj[key], obj[key]));
    sel.value = obj[key] ?? '';
    sel.onchange = () => setProp(obj, key, sel.value);
    wrap.append(sel);
    return wrap;
}

/** Lista: zwykle {{źródło.lista}} z panelu danych, ewentualnie JSON wpisany ręcznie. */
function listField(obj, param) {
    const value = obj[param.name];
    const wrap = field(param.name, '{{źródło.pole}} albo JSON', param.required);
    const input = document.createElement('textarea');
    input.value = typeof value === 'string' ? value : value === undefined ? '' : JSON.stringify(value);
    input.placeholder = '{{earthquakes.markers}}';
    input.oninput = () => {
        const raw = input.value.trim();
        let parsed = raw;
        if (raw.startsWith('[') || raw.startsWith('{')) {
            try { parsed = JSON.parse(raw); input.classList.remove('invalid'); } catch { input.classList.add('invalid'); return; }
        }
        setProp(obj, param.name, parsed);
    };
    input.onfocus = () => { state.focusedInput = input; };
    wrap.append(input);
    return wrap;
}

/** Włączony plugin JSON — odznaczenie zapisuje "enabled": false (po Zapisz, jak każda zmiana pliku). */
function enabledField() {
    const wrap = field('enabled', 'odznacz, żeby się nie losował');
    const box = Object.assign(document.createElement('input'), { type: 'checkbox', checked: state.def.enabled !== false });
    box.style.width = 'auto';
    box.onchange = () => change(() => { state.def = withEnabled(state.def, box.checked); });
    wrap.append(box);
    if (state.def.enabled === false) wrap.append(note('Wyłączony: nie rysuje się na slajdach, nie losuje w pick i nie pobiera danych. Podgląd poniżej działa dalej.'));
    return wrap;
}

/** Rozmiar pluginu w ćwiartkach wysokości slajdu — "size": 1–4 w plugins/<id>.json, bez pola = 2 (połowa). */
/** Rozmiar pluginu w ćwiartkach wysokości slajdu — "size": 1–4 w plugins/<id>.json, bez pola = 2 (połowa),
    albo placeholder z danych ("{{curiosity.size}}"), gdy rozmiar zależy od dzisiejszych danych (np. proporcji zdjęcia). */
const pluginSize = (def) => [1, 2, 3, 4].includes(def.size) ? def.size : 2;
const dynamicSize = (def) => typeof def.size === 'string';
const sizeLabel = (def) => dynamicSize(def) ? `z danych (${def.size})` : `${pluginSize(def)}/4`;

const SIZE_HINTS = {
    1: 'Preferowany rozmiar — najłatwiej mieści się w puli.',
    2: '',
    3: 'Tylko w ostateczności: na slajdzie zostaje 1/4 na inne pluginy.',
    4: 'Plugin zajmie cały slajd i nic obok niego się nie wylosuje — nowe pluginy nie powinny mieć 4/4.',
};

function sizeField() {
    const wrap = field('size', 'ile miejsca na slajdzie zajmuje plugin');
    const select = document.createElement('select');
    for (const n of [1, 2, 3, 4]) {
        select.append(Object.assign(document.createElement('option'), { value: n, textContent: `${n}/4 slajdu${n === 2 ? ' (domyślnie)' : ''}` }));
    }
    select.append(Object.assign(document.createElement('option'), { value: 'data', textContent: 'z danych — rozmiar dnia' }));
    select.value = dynamicSize(state.def) ? 'data' : pluginSize(state.def);

    // "size" zaraz za label/enabled (czytelniej w pliku); domyślne 2 nie ma pola wcale
    const save = (value) => change(() => {
        const { label, enabled, size, ...rest } = state.def;
        state.def = {
            ...(label !== undefined ? { label } : {}),
            ...(enabled !== undefined ? { enabled } : {}),
            ...(value === 2 ? {} : { size: value }),
            ...rest,
        };
    });
    select.onchange = () => save(select.value === 'data'
        ? `{{${state.def.sources?.[0] ?? state.name}.size}}`
        : Number(select.value));
    wrap.append(select);

    if (dynamicSize(state.def)) {
        // Placeholder do pola z danych źródła (1–4); bez danych albo poza zakresem plugin dostaje 2/4
        const input = Object.assign(document.createElement('input'), { type: 'text', value: state.def.size, placeholder: '{{źródło.size}}' });
        input.onchange = () => save(input.value.trim() || 2);
        wrap.append(input, note('Rozmiar liczy źródło na każdy dzień (np. z proporcji zdjęcia). Podgląd pokazuje dzisiejszy; przy losowaniu plugin, który się nie mieści, ustępuje miejsca następnemu.'));
    } else {
        const hint = SIZE_HINTS[pluginSize(state.def)];
        if (hint) {
            const el = note(hint);
            if (pluginSize(state.def) === 4) el.classList.add('size-warn');
            wrap.append(el);
        }
    }
    wrap.append(note('Przy losowaniu z puli suma rozmiarów pluginów na slajdzie nie przekracza 4/4.'));
    return wrap;
}

/**
 * Wysokość bloku w VerticalSplit, w ćwiartkach slajdu. Blok Plugin — "size" (nadpisuje rozmiar z plugins/<id>.json),
 * inne bloki i pule — "quarters" (pula z quarters ma tyle miejsca na losowanie). Puste = rozmiar domyślny.
 */
function heightField(node) {
    const key = node.type === 'Plugin' ? 'size' : 'quarters';
    const own = node.type === 'Plugin' && node.plugin ? state.jsonPlugins.find((p) => p.name === node.plugin)?.size : undefined;
    const fallback = node.type !== 'Plugin' ? (node.pick ? 'to, co zostało' : 'z zawartości')
        : typeof own === 'string' ? 'z danych — rozmiar dnia'
        : `${own ?? 2}/4${node.plugin ? ' — z pluginu' : ''}`;

    const wrap = field('wysokość', 'ile ćwiartek slajdu zajmuje blok');
    const select = document.createElement('select');
    select.append(Object.assign(document.createElement('option'), { value: '', textContent: `domyślnie (${fallback})` }));
    for (const n of [1, 2, 3, 4]) select.append(Object.assign(document.createElement('option'), { value: n, textContent: `${n}/4 slajdu` }));
    select.value = [1, 2, 3, 4].includes(Number(node[key])) ? String(node[key]) : '';
    select.onchange = () => setProp(node, key, select.value === '' ? undefined : Number(select.value));
    wrap.append(select);

    const hint = node.type === 'Plugin' && SIZE_HINTS[Number(node[key])];
    if (hint) {
        const el = note(hint);
        if (Number(node[key]) === 4) el.classList.add('size-warn');
        wrap.append(el);
    }
    if (node.type === 'Plugin' && node[key] !== undefined) {
        wrap.append(note('Nadpisuje rozmiar tylko na tym slajdzie — plik pluginu się nie zmienia.'));
    }
    return wrap;
}

function checkField(obj, param) {
    const wrap = field(param.name, defaultHint(param));
    const box = document.createElement('input');
    box.type = 'checkbox';
    box.style.width = 'auto';
    box.checked = obj[param.name] ?? param.default ?? false;
    box.onchange = () => setProp(obj, param.name, box.checked);
    wrap.append(box);
    return wrap;
}

function colorField(obj, param) {
    const wrap = textField(obj, param.name, { hint: defaultHint(param), placeholder: param.default ?? '' });
    const text = wrap.querySelector('input');
    const picker = document.createElement('input');
    picker.type = 'color';
    const sync = () => {
        const v = text.value || param.default || '#ffffff';
        if (/^#[0-9a-f]{6}$/i.test(v)) picker.value = v;
    };
    sync();
    picker.oninput = () => {
        text.value = picker.value.toUpperCase();
        text.oninput();
    };
    text.addEventListener('input', sync);

    const rowEl = document.createElement('div');
    rowEl.className = 'color-row';
    text.replaceWith(rowEl);
    rowEl.append(picker, text);
    return wrap;
}

function sourcesField() {
    const wrap = field('sources', 'dane dostępne w szablonie');
    const box = document.createElement('div');
    box.className = 'checks';
    for (const s of state.sources) {
        const l = document.createElement('label');
        const c = document.createElement('input');
        c.type = 'checkbox';
        c.checked = (state.def.sources || []).includes(s.id);
        c.onchange = async () => {
            change(() => {
                const set = new Set(state.def.sources || []);
                c.checked ? set.add(s.id) : set.delete(s.id);
                state.def.sources = state.sources.map((x) => x.id).filter((id) => set.has(id));
            });
            await loadSourceData();
            renderData();
        };
        l.append(c, document.createTextNode(`${s.id} — ${s.label}`));
        box.append(l);
    }
    wrap.append(box);
    return wrap;
}

const heading = (t) => Object.assign(document.createElement('h3'), { textContent: t });
const sub = (t) => Object.assign(document.createElement('h4'), { textContent: t });
const note = (t) => Object.assign(document.createElement('p'), { className: 'empty', textContent: t });

// ---------------------------------------------------------------- Dane źródeł

/** Liście danych jako ścieżki {{źródło.a.b}}; z długich list pokazujemy tylko pierwszy element. */
function flatten(value, prefix, out) {
    if (Array.isArray(value)) {
        out.push([prefix, `[${value.length} elementów]`, false]);
        if (value.length) flatten(value[0], prefix + '.0', out);
    } else if (value && typeof value === 'object') {
        for (const [k, v] of Object.entries(value)) flatten(v, prefix + '.' + k, out);
    } else {
        out.push([prefix, value === null ? 'null' : String(value), true]);
    }
    return out;
}

function renderData() {
    const data = $('data');
    data.innerHTML = '';
    const ids = docSources();
    if (!ids.length) return;

    data.append(sub('Dane — kliknij, by wstawić {{…}}'));
    const datalist = document.createElement('datalist');
    datalist.id = 'data-paths';

    for (const id of ids) {
        const entry = state.sourceData[id] || {};
        const box = document.createElement('div');
        box.className = 'source';

        const head = document.createElement('div');
        head.className = 'source-head';
        const title = document.createElement('strong');
        title.textContent = id;
        title.style.cursor = 'pointer';
        title.title = 'Szczegóły integracji';
        title.onclick = () => openIntegration(id);
        const st = document.createElement('span');
        st.className = entry.ok ? 'ok' : 'bad';
        st.textContent = entry.ok ? 'OK' : 'błąd';
        head.append(title, st);
        box.append(head);

        const meta = document.createElement('div');
        meta.className = 'source-meta';
        meta.textContent = [entry.label, entry.fetchedAt && 'pobrano ' + new Date(entry.fetchedAt).toLocaleString('pl-PL'), entry.durationMs && entry.durationMs + ' ms', entry.error].filter(Boolean).join(' · ');
        box.append(meta);

        datalist.append(new Option(id));
        for (const [path, val, leaf] of flatten(entry.data ?? null, id, [])) {
            datalist.append(new Option(path));
            const p = document.createElement('div');
            p.className = 'path';
            const k = document.createElement('span');
            k.className = 'key';
            k.textContent = path;
            const v = document.createElement('span');
            v.className = 'val';
            v.textContent = val;
            v.title = val;
            p.append(k, v);
            p.onclick = () => insertPlaceholder(path);
            if (!leaf) p.title = 'Wstaw całą listę (np. do markers w WorldMap)';
            box.append(p);
        }
        data.append(box);
    }
    data.append(datalist);
}

function insertPlaceholder(path) {
    const input = state.focusedInput;
    if (!input || !document.body.contains(input)) {
        navigator.clipboard?.writeText(`{{${path}}}`);
        toast(`Skopiowano {{${path}}} — kliknij najpierw pole tekstowe, żeby wstawić bezpośrednio`);
        return;
    }
    const text = `{{${path}}}`;
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    input.value = input.value.slice(0, start) + text + input.value.slice(end);
    input.focus();
    input.selectionStart = input.selectionEnd = start + text.length;
    input.dispatchEvent(new Event('input'));
}

// ---------------------------------------------------------------- Integracje

function switchTab(tab) {
    state.tab = tab;
    for (const btn of document.querySelectorAll('.tabs button')) btn.classList.toggle('active', btn.dataset.tab === tab);
    $('tab-props').hidden = tab !== 'props';
    $('tab-integrations').hidden = tab !== 'integrations';
    localSet('tab', tab);
    if (tab === 'integrations') renderIntegrations();
}

function openIntegration(id) {
    state.openSources.add(id);
    switchTab('integrations');
}

function ago(iso) {
    if (!iso) return '—';
    const sec = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (sec < 60) return 'przed chwilą';
    if (sec < 3600) return Math.round(sec / 60) + ' min temu';
    if (sec < 86400) return Math.round(sec / 3600) + ' h temu';
    return Math.round(sec / 86400) + ' dni temu';
}

function isStale(s) {
    return s.maxFallbackAge && s.fetchedAt && (Date.now() - new Date(s.fetchedAt).getTime()) / 1000 > s.maxFallbackAge;
}

async function renderIntegrations() {
    const seq = state.integrationsSeq = (state.integrationsSeq || 0) + 1;
    const list = await api('sources');
    const box = document.createDocumentFragment();

    const bar = document.createElement('div');
    bar.className = 'int-toolbar';
    const info = document.createElement('span');
    const failing = list.filter((s) => s.ok === false).length;
    info.textContent = `${list.length} źródeł` + (failing ? ` · ${failing} z błędem` : '');
    const all = document.createElement('button');
    all.textContent = 'Pobierz wszystkie';
    all.onclick = async () => {
        all.disabled = true;
        for (const s of list) await refreshSource(s.id, false);
        all.disabled = false;
        await afterRefresh();
    };
    bar.append(info, all);
    box.append(bar);

    for (const s of list) box.append(await integrationCard(s));

    // Starsze wywołanie, które skończyło później, nie może nadpisać nowszego widoku
    if (seq !== state.integrationsSeq) return;
    const panel = $('integrations').closest('.panel');
    const scroll = panel.scrollTop;
    $('integrations').replaceChildren(box);
    panel.scrollTop = scroll;
}

async function integrationCard(s) {
    const open = state.openSources.has(s.id);
    const card = document.createElement('div');
    card.className = 'int' + (open ? ' open' : '');

    const head = document.createElement('div');
    head.className = 'int-head';
    const dot = document.createElement('span');
    dot.className = 'dot ' + (s.ok === false ? 'bad' : isStale(s) ? 'stale' : s.ok ? 'ok' : '');
    const name = document.createElement('span');
    name.className = 'name';
    name.textContent = s.id;
    const small = document.createElement('small');
    small.textContent = s.label;
    name.append(small);
    if (s.describe?.canSkip) {
        name.append(' ', chip('może pominąć', 'chip-skip', 'Gdy danego dnia nie ma danych, plugin zwraca skip()'
            + '\n— w kontenerze z pick w jego miejsce losuje się inny blok.'));
    }

    const btn = document.createElement('button');
    btn.textContent = state.refreshing.has(s.id) ? 'Pobieram…' : 'Pobierz teraz';
    btn.disabled = state.refreshing.has(s.id);
    btn.onclick = async (e) => {
        e.stopPropagation();
        await refreshSource(s.id);
    };
    head.append(dot, name, btn);
    head.onclick = () => {
        open ? state.openSources.delete(s.id) : state.openSources.add(s.id);
        renderIntegrations();
    };
    card.append(head);

    const meta = document.createElement('div');
    meta.className = 'int-meta';
    const parts = [
        s.cached ? 'dane: ' + ago(s.fetchedAt) : 'jeszcze nie pobierano',
        s.durationMs !== null ? s.durationMs + ' ms' : null,
        s.requests ? s.requests + ' zapyt.' : null,
        s.usedBy.length ? 'slajdy: ' + s.usedBy.join(', ') : 'nieużywane',
    ].filter(Boolean);
    for (const p of parts) meta.append(Object.assign(document.createElement('span'), { textContent: p }));
    if (isStale(s)) meta.append(Object.assign(document.createElement('span'), { className: 'warn', textContent: 'starsze niż limit zastępstwa — CRON by je pominął' }));
    if (s.ok === false) meta.append(Object.assign(document.createElement('span'), { className: 'err', textContent: `Ostatnia próba ${ago(s.attemptedAt)}: ${s.error}` }));
    card.append(meta);

    if (open) card.append(await integrationBody(s));
    return card;
}

async function integrationBody(s) {
    const body = document.createElement('div');
    body.className = 'int-body';

    // Jak w refreshSource: wynik przez zmienną, żeby trafił do aktualnego state.fullEntries
    const entry = state.fullEntries[s.id] ?? await api('sources/' + s.id).catch((e) => ({ error: e.message }));
    state.fullEntries[s.id] = entry;

    body.append(h5('Konfiguracja'));
    const kv = document.createElement('dl');
    kv.className = 'kv';
    const describe = { ...s.describe, ...(s.maxFallbackAge ? { 'limit zastępstwa': Math.round(s.maxFallbackAge / 3600) + ' h' } : {}) };
    for (const [k, v] of Object.entries(describe)) {
        const dt = Object.assign(document.createElement('dt'), { textContent: k });
        const dd = document.createElement('dd');
        if (typeof v === 'string' && /^https?:\/\//.test(v)) {
            dd.append(Object.assign(document.createElement('a'), { href: v, target: '_blank', rel: 'noopener', textContent: v }));
        } else {
            dd.textContent = typeof v === 'object' ? Object.entries(v).map(([a, b]) => `${a}=${b}`).join('  ') : String(v);
        }
        kv.append(dt, dd);
    }
    body.append(kv);

    body.append(h5('Dane dla szablonu — kliknij wartość, by wstawić {{…}}'));
    body.append(jsonView(entry.data ?? null, s.id, (path) => {
        switchTab('props');
        insertPlaceholder(path);
    }));

    const requests = entry.requests ?? [];
    body.append(h5(`Odpowiedzi API (${requests.length})`));
    if (!requests.length) body.append(note(s.plugin || s.describe.file ? 'Brak nagranych zapytań (źródło lokalne albo pobrane przed włączeniem nagrywania).' : 'Brak nagranych zapytań — kliknij „Pobierz teraz”.'));
    for (const r of requests) {
        const req = document.createElement('div');
        req.className = 'req';
        const rh = document.createElement('div');
        rh.className = 'req-head';
        const url = Object.assign(document.createElement('span'), { className: 'url', textContent: `${r.method} ${r.url}` });
        const st = Object.assign(document.createElement('span'), {
            className: 'st' + (r.error || (r.status ?? 0) >= 400 ? ' bad' : ''),
            textContent: `${r.status ?? '—'} · ${r.ms} ms · ${formatBytes(r.bytes)}`,
        });
        rh.append(url, st);
        req.append(rh);
        if (r.error) req.append(note(r.error));
        req.append(r.body === null ? note('Odpowiedź za duża, żeby ją zapisać.') : jsonView(r.body, null));
        body.append(req);
    }
    return body;
}

async function refreshSource(id, rerender = true) {
    state.refreshing.add(id);
    if (state.tab === 'integrations') renderIntegrations();
    try {
        // Najpierw do zmiennej: w trakcie pobierania /api/changes może podmienić state.fullEntries na nowy obiekt,
        // a `state.fullEntries[id] = await …` zapisałoby wynik do starego (obiekt jest wyliczany przed await)
        const e = await api(`sources/${id}/refresh`, { method: 'POST' });
        state.fullEntries[id] = e;
        toast(e.ok ? `${id}: pobrano (${e.durationMs} ms)` : `${id}: błąd — ${e.error}`);
    } catch (e) {
        toast(`${id}: ${e.message}`);
    } finally {
        state.refreshing.delete(id);
    }
    if (rerender) await afterRefresh();
}

async function afterRefresh() {
    await loadSourceData();
    renderData();
    schedulePreview(0);
    if (state.tab === 'integrations') await renderIntegrations();
}

const h5 = (t) => Object.assign(document.createElement('h5'), { textContent: t });

function formatBytes(b) {
    return b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(1) + ' kB' : (b / 1048576).toFixed(1) + ' MB';
}

/** Zwijane drzewo JSON. pathPrefix + onPick → liście są klikalne i zwracają ścieżkę {{…}}. */
function jsonView(value, pathPrefix, onPick = null) {
    const root = document.createElement('div');
    root.className = 'json';
    root.append(jsonNode(null, value, pathPrefix, onPick, 0));
    return root;
}

function jsonNode(key, value, path, onPick, depth) {
    if (value !== null && typeof value === 'object') {
        const entries = Array.isArray(value) ? value.map((v, i) => [i, v]) : Object.entries(value);
        const d = document.createElement('details');
        d.open = depth < 1 || (depth < 2 && entries.length <= 8);
        const sum = document.createElement('summary');
        if (key !== null) sum.append(Object.assign(document.createElement('span'), { className: 'k', textContent: key + ': ' }));
        sum.append(Array.isArray(value) ? `[${entries.length}]` : `{${entries.length}}`);
        d.append(sum);

        // Duże listy (np. historia BTC) rozwijamy dopiero po otwarciu i tylko początek
        const fill = () => {
            if (d.dataset.filled) return;
            d.dataset.filled = '1';
            const limit = 50;
            for (const [k, v] of entries.slice(0, limit)) d.append(jsonNode(k, v, path === null ? null : `${path}.${k}`, onPick, depth + 1));
            if (entries.length > limit) d.append(Object.assign(document.createElement('div'), { className: 'more', textContent: `… i jeszcze ${entries.length - limit}` }));
        };
        if (d.open) fill();
        d.addEventListener('toggle', fill);
        return d;
    }

    const leaf = document.createElement('div');
    leaf.className = 'leaf' + (onPick && path ? ' pick' : '');
    if (key !== null) leaf.append(Object.assign(document.createElement('span'), { className: 'k', textContent: key + ': ' }));
    const cls = typeof value === 'string' ? 's' : typeof value === 'number' ? 'n' : 'b';
    const text = typeof value === 'string' ? JSON.stringify(value) : String(value);
    leaf.append(Object.assign(document.createElement('span'), { className: cls, textContent: text }));
    leaf.title = (path ? `{{${path}}}  ` : '') + text;
    if (onPick && path) leaf.onclick = () => onPick(path);
    return leaf;
}

// ---------------------------------------------------------------- Plan publikacji i nowe slajdy

function renderPlan() {
    const list = $('plan-list');
    list.innerHTML = '';
    const entries = state.stories.entries;

    entries.forEach((e, i) => {
        const li = document.createElement('li');
        li.className = 'plan-row' + (e.enabled ? '' : ' off');

        const box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = e.enabled;
        box.title = e.enabled ? 'Publikowany — kliknij, by wyłączyć' : 'Wyłączony — kliknij, by publikować';
        box.onchange = () => savePlan(entries.map((x, j) => (j === i ? { ...x, enabled: box.checked } : x)));

        const label = document.createElement('span');
        label.className = 'label';
        label.textContent = caps(e.label);
        label.append(Object.assign(document.createElement('small'), { textContent: e.kind === 'code' ? `kod PHP · ${e.id}` : `${e.id}.json` }));
        if (!e.exists) label.append(Object.assign(document.createElement('div'), { className: 'warn', textContent: 'brak pliku — CRON pominie ten wpis' }));
        if (e.error) label.append(Object.assign(document.createElement('div'), { className: 'warn', textContent: e.error }));

        const up = Object.assign(document.createElement('button'), { textContent: '↑', title: 'Wcześniej', disabled: i === 0 });
        up.onclick = () => savePlan(move(entries, i, i - 1));
        const down = Object.assign(document.createElement('button'), { textContent: '↓', title: 'Później', disabled: i === entries.length - 1 });
        down.onclick = () => savePlan(move(entries, i, i + 1));
        li.append(box, label, up, down);

        if (e.kind === 'slide' && e.exists) {
            const open = Object.assign(document.createElement('button'), { textContent: 'Otwórz' });
            open.onclick = () => { $('plan').close(); switchSlide(e.id); };
            li.append(open);
        }
        list.append(li);
    });

    const un = $('plan-unplanned');
    un.innerHTML = '';
    if (state.stories.unplanned.length) {
        un.append(sub('Poza planem'));
        for (const s of state.stories.unplanned) {
            const row = document.createElement('div');
            row.className = 'plan-row off';
            const label = Object.assign(document.createElement('span'), { className: 'label', textContent: caps(s.label) });
            label.append(Object.assign(document.createElement('small'), { textContent: `${s.id}.json` }));
            const add = Object.assign(document.createElement('button'), { textContent: 'Dodaj do planu' });
            add.onclick = () => savePlan([...entries, { id: s.id, enabled: false }]);
            row.append(label, add);
            un.append(row);
        }
    }
}

function move(list, from, to) {
    const copy = list.slice();
    const [item] = copy.splice(from, 1);
    copy.splice(to, 0, item);
    return copy;
}

async function savePlan(entries) {
    try {
        state.stories = await api('stories', { method: 'PUT', body: { entries: entries.map(({ id, enabled }) => ({ id, enabled })) } });
        renderPlan();
        await fillSlideSelect();
    } catch (e) {
        toast('Błąd planu: ' + e.message);
    }
}

/** Przełączenie dokumentu — klucz „rodzaj:nazwa” z listy albo sama nazwa slajdu (plan publikacji). */
async function switchSlide(key) {
    const { kind, name } = parseDoc(key);
    if (kind === state.kind && name === state.name) return;
    if (isDirty() && !confirm('Masz niezapisane zmiany. Przełączyć dokument i je porzucić?')) {
        $('slide-select').value = docKey();
        return;
    }
    await openDoc(docKey(kind, name));
}

/** Slajdy, które wstawiają plugin JSON (na podstawie listy slajdów nie da się — sprawdzamy ich definicje). */
function usedInSlides(id) {
    return state.usedJsonPlugins?.[id] ?? [];
}

async function loadJsonPluginUsage() {
    const slides = await api('slides');
    const usage = {};
    await Promise.all(slides.map(async (s) => {
        try {
            const def = (await api('slides/' + s.name)).definition;
            const walk = (n) => {
                if (n?.plugin) (usage[n.plugin] ||= []).push(s.name);
                (n?.children || []).forEach(walk);
            };
            walk(def.body);
        } catch { /* zepsuty slajd — pomijamy */ }
    }));
    state.usedJsonPlugins = usage;
}

async function createSlide(e) {
    e.preventDefault();
    const err = $('new-slide-error');
    err.hidden = true;
    try {
        const kind = $('new-slide-kind').value;
        const name = $('new-slide-name').value.trim();
        const created = await api(kind, { method: 'POST', body: { name, title: $('new-slide-title').value } });
        $('new-slide').close();
        $('new-slide-form').reset();
        if (isDirty() && !confirm('Masz niezapisane zmiany. Porzucić je i otworzyć nowy dokument?')) {
            await fillSlideSelect();
            return;
        }
        await fillSlideSelect();
        await openDoc(docKey(kind, created.name));
        toast(kind === 'plugins'
            ? `Utworzono plugins/${created.name}.json — wstaw go w slajdzie blokiem Plugin`
            : `Utworzono slides/${created.name}.json — w planie jako wyłączony`);
    } catch (ex) {
        err.textContent = ex.message;
        err.hidden = false;
    }
}

// ---------------------------------------------------------------- Zmiany na dysku (np. z Claude Code w terminalu)

async function pollChanges() {
    try {
        const ch = await api('changes');
        const diskMtime = ch[state.kind]?.[state.name];

        if (diskMtime && diskMtime !== state.mtime) {
            if (!isDirty()) {
                const keep = state.selected;
                await openDoc(docKey());
                if (keep === 'slide' || nodeAt(keep) !== undefined) select(keep);
                toast('Wczytano zmiany z dysku');
            } else {
                state.diskMtime = diskMtime;
                $('banner').hidden = false;
            }
        }

        // Nowe dane w cache (np. po pobraniu z terminala) → odśwież panel danych i podgląd
        const cacheKey = JSON.stringify(ch.cache);
        if (state.cacheKey !== null && state.cacheKey !== cacheKey) {
            state.fullEntries = {};
            await loadSourceData();
            renderData();
            schedulePreview(0);
            if (state.tab === 'integrations' && !state.refreshing.size) await renderIntegrations();
        }
        state.cacheKey = cacheKey;

        const known = [...$('slide-select').options].map((o) => o.value).sort().join();
        const onDisk = [
            ...Object.keys(ch.slides).map((n) => docKey('slides', n)),
            ...Object.keys(ch.plugins || {}).map((n) => docKey('plugins', n)),
        ].sort().join();
        const storiesChanged = state.storiesMtime !== null && ch.stories !== state.storiesMtime;
        state.storiesMtime = ch.stories;

        // Zmieniony plugin JSON zmienia wygląd slajdów, które go wstawiają — odśwież podgląd
        const pluginsKey = JSON.stringify(ch.plugins || {});
        if (state.pluginsKey !== undefined && state.pluginsKey !== pluginsKey && state.kind === 'slides') schedulePreview(0);
        state.pluginsKey = pluginsKey;

        if (storiesChanged || onDisk !== known) await refreshSlideList();
    } catch {
        // serwer chwilowo niedostępny — spróbujemy przy następnym cyklu
    }
}

async function refreshSlideList() {
    await fillSlideSelect();
    if ($('plan').open) renderPlan();
}

// ---------------------------------------------------------------- Pasek statusu

const DAY = 86400;

async function loadStatus(refresh = false) {
    const bar = $('statusbar');
    bar.classList.add('checking');
    try {
        state.status = await api('status' + (refresh ? '?refresh=1' : ''));
        renderStatus();
    } catch (e) {
        $('statusbar-items').replaceChildren(pill('error', 'Status', 'niedostępny', e.message));
    } finally {
        bar.classList.remove('checking');
    }
}

function pill(level, label, value, tip = '', onClick = null) {
    const el = document.createElement('span');
    el.className = 'pill ' + level + (onClick ? ' clickable' : '');
    el.innerHTML = '<span class="dot"></span>';
    el.append(label + ' ', Object.assign(document.createElement('b'), { textContent: value }));
    if (tip) el.append(Object.assign(document.createElement('span'), { className: 'tip', textContent: tip }));
    if (onClick) el.onclick = onClick;
    return el;
}

const unixDate = (ts) => new Date(ts * 1000).toLocaleString('pl-PL', { dateStyle: 'medium', timeStyle: 'short' });
const daysLeft = (ts) => Math.floor((ts - Date.now() / 1000) / DAY);

function metaPill(m) {
    if (!m.valid) {
        return pill('error', 'Instagram', 'token nieważny',
            (m.message || '') + '\n\nNowy token: php token.php <krótki_token>');
    }
    const lines = [];
    if (m.type) lines.push('Typ: ' + m.type);
    if (m.scopes?.length) lines.push('Uprawnienia: ' + m.scopes.join(', '));

    if (!m.canExpire) {
        lines.push('', 'Token działa, ale bez APP_ID i APP_SECRET w .env nie da się sprawdzić daty wygaśnięcia.');
        return pill('ok', 'Instagram', 'token OK', lines.join('\n'));
    }

    // Wygasa to, co nastąpi wcześniej: sam token albo dostęp aplikacji do danych
    const limits = [m.expiresAt || null, m.dataAccessExpiresAt || null].filter(Boolean);
    if (m.expiresAt) lines.push('Token wygasa: ' + unixDate(m.expiresAt));
    else lines.push('Token: bezterminowy');
    if (m.dataAccessExpiresAt) lines.push('Dostęp do danych wygasa: ' + unixDate(m.dataAccessExpiresAt));
    if (!limits.length) return pill('ok', 'Instagram', 'bezterminowy', lines.join('\n'));

    const days = daysLeft(Math.min(...limits));
    if (days <= 14) lines.push('', 'Odnów: php token.php <krótki_token>');
    return pill(days <= 3 ? 'error' : days <= 14 ? 'warn' : 'ok', 'Instagram', days <= 0 ? 'dziś wygasa' : `ważny ${days} dni`, lines.join('\n'));
}

function nasaPill(n) {
    if (!n.valid) return pill('error', 'NASA', 'klucz nieważny', n.message || '');
    const lines = [n.demoKey ? 'Używasz DEMO_KEY — mocno ograniczony limit.' : 'Klucz działa.'];
    if (n.limit !== null) lines.push(`Pozostało ${n.left} z ${n.limit} zapytań w tej godzinie.`);
    const low = n.limit && n.left / n.limit < 0.1;
    return pill(n.demoKey || low ? 'warn' : 'ok', 'NASA', n.limit !== null ? `${n.left}/${n.limit}` : 'OK', lines.join('\n'));
}

function renderStatus() {
    const s = state.status;
    const items = [metaPill(s.meta), nasaPill(s.nasa)];
    // Xdebug w trybie z php.ini (np. develop) spowalnia rysowanie podglądu kilkanaście razy
    if (s.xdebug) items.unshift(pill('error', 'Xdebug', `${s.xdebug} — podgląd ~15× wolniej`,
        'Serwer edytora działa z włączonym Xdebugiem. Zatrzymaj go i uruchom ponownie:\ncomposer admin\nalbo: php -d xdebug.mode=off -S 127.0.0.1:8080 admin/router.php'));

    const src = s.sources;
    const bad = src.failed.length + src.stale.length;
    const srcTip = [
        `${src.ok} z ${src.total} źródeł ma aktualne dane.`,
        src.failed.length ? 'Błąd: ' + src.failed.join(', ') : '',
        src.stale.length ? 'Za stare dane: ' + src.stale.join(', ') : '',
        src.oldest ? 'Najstarsze pobranie: ' + ago(new Date(src.oldest * 1000).toISOString()) : '',
        '', 'Kliknij — zakładka Integracje',
    ].filter((l, i, a) => l || a[i - 1]).join('\n');
    items.push(pill(src.failed.length ? 'error' : bad ? 'warn' : 'ok', 'Źródła', `${src.total - bad}/${src.total}`, srcTip, () => switchTab('integrations')));

    const plan = s.plan;
    items.push(pill(plan.broken.length ? 'error' : plan.enabled ? 'ok' : 'warn', 'Plan', `${plan.enabled}/${plan.total} slajdów`,
        plan.broken.length ? 'Zepsute lub brakujące: ' + plan.broken.join(', ') : 'Włączone slajdy w stories.json\nKliknij — Plan publikacji',
        () => $('plan-open').click()));

    items.push(pill(s.mode === 'production' ? 'info' : 'idle', 'Tryb', s.mode === 'production' ? 'produkcja' : 'debug',
        s.mode === 'production' ? 'DEBUG=false w .env — run.php publikuje na Instagramie.' : 'run.php tylko generuje obrazy, nic nie publikuje (DEBUG w .env).'));

    if (s.render) {
        const at = new Date(s.render.at * 1000).toISOString();
        items.push(pill('idle', 'Ostatni render', ago(at), `${s.render.file}\n${s.render.count} obrazów w ig-stories/`));
    }

    if (s.commit) {
        const c = s.commit;
        items.push(pill(c.dirty ? 'warn' : 'idle', 'Commit', ago(new Date(c.at * 1000).toISOString()),
            `${c.hash} · ${c.subject}\n${unixDate(c.at)}` + (c.dirty ? `\n\n${c.dirty} niezacommitowanych zmian` : '')));
    }

    // Deploy wysyła working tree — „za commitem” oznacza, że na serwerze jest kod sprzed ostatniego commita
    const d = s.deploy;
    if (d) {
        const behind = s.commit && d.commit && d.commit !== s.commit.hash;
        items.push(pill(behind ? 'warn' : 'idle', 'Deploy', ago(new Date(d.at * 1000).toISOString()),
            [`${unixDate(d.at)}${d.run ? ' · z próbnym runem' : ''}`,
             `Commit ${d.commit || '—'}${d.dirty ? ` + ${d.dirty} niezacommitowanych zmian` : ''}`,
             behind ? `\nOd tego czasu jest nowszy commit (${s.commit.hash}) — serwer może mieć starszy kod.` : ''].filter(Boolean).join('\n')));
    } else {
        items.push(pill('idle', 'Deploy', 'brak danych', 'Ślad pojawi się po najbliższym ./deploy.sh (.igapi/last-deploy.json).'));
    }

    $('statusbar-items').replaceChildren(...items);
    renderStatusChecked();
}

function renderStatusChecked() {
    if (state.status) $('statusbar-checked').textContent = 'sprawdzono ' + ago(state.status.checkedAt);
}

// ---------------------------------------------------------------- Drobiazgi

function renderDirty() {
    $('dirty').hidden = !isDirty();
    $('undo').disabled = !state.undo.length;
    $('redo').disabled = !state.redo.length;
    document.title = (isDirty() ? '● ' : '') + 'IGAPI Editor — ' + docFile();
}

function renderAll() {
    renderTree();
    renderProps();
    renderDirty();
    schedulePreview(0);
}

let toastTimer;
function toast(text) {
    const t = $('toast');
    t.textContent = text;
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, 2500);
}

function localGet(key) {
    try { return localStorage.getItem('igapi.' + key); } catch { return null; }
}

function localSet(key, value) {
    try { localStorage.setItem('igapi.' + key, value); } catch { /* bez pamięci — trudno */ }
}

function isTyping() {
    const el = document.activeElement;
    return el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);
}

// ---------------------------------------------------------------- Start

async function init() {
    const schema = await api('schema');
    state.schema = schema.components;
    state.sources = schema.sources;

    const addType = $('add-type');
    for (const type of Object.keys(state.schema)) addType.add(new Option(type, type));

    $('slide-select').onchange = (e) => switchSlide(e.target.value);
    $('plan-open').onclick = async () => {
        await fillSlideSelect();
        renderPlan();
        $('plan').showModal();
    };
    $('plan-close').onclick = () => $('plan').close();
    $('new-slide-kind').onchange = () => {
        $('new-slide-note').textContent = $('new-slide-kind').value === 'plugins'
            ? 'Plugin to grupa bloków z własnymi źródłami — wstawisz go w slajdzie (także do puli ŚWIATA).'
            : 'Slajd trafi do planu publikacji jako wyłączony — włączysz go, gdy będzie gotowy.';
    };
    $('new-slide-open').onclick = () => {
        $('new-slide-error').hidden = true;
        $('new-slide').showModal();
        $('new-slide-name').focus();
    };
    $('new-slide-close').onclick = () => $('new-slide').close();
    $('new-slide-form').onsubmit = createSlide;
    $('save').onclick = () => save().catch((e) => toast('Błąd zapisu: ' + e.message));
    $('undo').onclick = undo;
    $('redo').onclick = redo;
    $('add').onclick = addNode;
    $('up').onclick = () => moveNode(-1);
    $('down').onclick = () => moveNode(1);
    $('duplicate').onclick = duplicateNode;
    $('remove').onclick = removeNode;
    $('banner-load').onclick = () => openDoc(docKey());
    $('banner-keep').onclick = () => { $('banner').hidden = true; state.mtime = state.diskMtime; };

    for (const btn of document.querySelectorAll('.tabs button')) btn.onclick = () => switchTab(btn.dataset.tab);

    const overlay = $('overlay');
    overlay.onmousemove = (e) => renderBoxes(boxAt(e)?.path);
    overlay.onmouseleave = () => renderBoxes();
    overlay.onclick = (e) => { const b = boxAt(e); if (b) select(b.path); };
    window.onresize = () => renderBoxes();

    document.addEventListener('keydown', (e) => {
        const mod = e.ctrlKey || e.metaKey;
        if (mod && e.key.toLowerCase() === 's') {
            e.preventDefault();
            $('save').click();
        } else if (mod && e.key.toLowerCase() === 'z' && !isTyping()) {
            e.preventDefault();
            e.shiftKey ? redo() : undo();
        } else if (e.key === 'Delete' && !isTyping() && state.selected !== 'slide') {
            removeNode();
        }
    });
    window.onbeforeunload = (e) => { if (isDirty()) e.preventDefault(); };

    await loadSlides();
    if (localGet('tab') === 'integrations') switchTab('integrations');
    setInterval(pollChanges, 1500);

    // Serwer trzyma wynik sprawdzeń sieciowych 10 min — częstsze odpytywanie odświeża tylko dane lokalne
    $('statusbar-refresh').onclick = () => loadStatus(true);
    loadStatus();
    setInterval(loadStatus, 60_000);
    setInterval(renderStatusChecked, 15_000);
}

init().catch((e) => {
    $('status').className = 'status error';
    $('status').textContent = 'Nie udało się uruchomić edytora: ' + e.message;
});
