// Opisy i ikony są tu; co jest dziś na story, statusy i rendery przychodzą z data/today.json (run.php → WebExport).

const SLIDES = [
    { id: 'palestine', title: 'GAZA', desc: 'Ofiary wojny w Strefie Gazy według Tech for Palestine.', plugins: [] },
    { id: 'swiat', title: 'ŚWIAT', desc: 'Gospodarka, klimat i Ziemia — bloki z puli wypełniają cały slajd.', plugins: ['bitcoin', 'population', 'earthquakes', 'electricity', 'climate', 'bigMac', 'fearGreed', 'seaIce', 'currencies', 'inflation', 'fuel', 'elNino', 'housing'] },
    { id: 'kosmos', title: 'KOSMOS', desc: 'Starty rakiet, ISS, sondy i planety — plus asteroidy.', plugins: ['curiosity', 'launch', 'iss', 'satellites', 'voyager', 'planets', 'solarCycle', 'flatEarth', 'asteroids'] },
    { id: 'polska', title: 'POLSKA', desc: 'Pogoda w dziewięciu miastach, a pod nią bloki z puli.', plugins: ['weather', 'aurora', 'polishGames', 'planes', 'mushrooms', 'doctors', 'avalanche', 'trains'] },
    { id: 'remembrance', title: 'PAMIĘĆ', desc: 'Rocznice i wspomnienia.', plugins: [], soon: true },
];

// icon = nazwa ikony Lucide
const PLUGINS = {
    bitcoin:     { name: 'Bitcoin', icon: 'bitcoin', src: 'CoinGecko', desc: 'Kurs BTC w złotówkach i wykres z ostatnich tygodni.' },
    population:  { name: 'Populacja', icon: 'users', src: 'Bank Światowy', desc: 'Ile nas jest — świat i Polska w liczbach.' },
    earthquakes: { name: 'Trzęsienia ziemi', icon: 'activity', src: 'USGS', desc: 'Wstrząsy M4.5+ z ostatniej doby na mapie świata.' },
    electricity: { name: 'Prąd w Europie', icon: 'zap', src: 'Nord Pool / Energy-Charts', desc: 'Hurtowe ceny prądu na dziś w strefach Europy.' },
    climate:     { name: 'Temperatura Ziemi', icon: 'thermometer', src: 'Copernicus', desc: 'Średnia globalna i odchylenia od 1940 roku.' },
    bigMac:      { name: 'Indeks Big Maca', icon: 'sandwich', src: 'The Economist', desc: 'Czy złoty jest zaniżony względem dolara.' },
    fearGreed:   { name: 'Strach i chciwość', icon: 'gauge', src: 'alternative.me', desc: 'Nastroje rynku kryptowalut w skali 0–100.' },
    seaIce:      { name: 'Lód morski', icon: 'snowflake', src: 'NSIDC', desc: 'Zasięg lodu w Arktyce i wokół Antarktydy.' },
    currencies:  { name: 'Koszyk walut', icon: 'coins', src: 'NBP', desc: 'Kursy walut wobec złotego.' },
    inflation:   { name: 'Inflacja w Polsce', icon: 'trending-up', src: 'Eurostat', desc: 'Inflacja na tle Unii Europejskiej.' },
    fuel:        { name: 'Ceny paliw', icon: 'fuel', src: 'Komisja Europejska', desc: 'Benzyna i diesel w krajach UE.' },
    elNino:      { name: 'El Niño', icon: 'waves', src: 'NOAA CPC', desc: 'Anomalia temperatury oceanu w regionie Niño 3.4 — wykres z dwóch lat.' },
    housing:     { name: 'Ceny mieszkań', icon: 'house', src: 'NBP', desc: 'Cena metra w Warszawie, zmiana w rok i mapa 17 miast.' },
    curiosity:   { name: 'Zdjęcie z Curiosity', icon: 'camera', src: 'NASA', desc: 'Najnowsze zdjęcie z łazika na Marsie.' },
    launch:      { name: 'Najbliższy start rakiety', icon: 'rocket', src: 'Launch Library 2', desc: 'Rakieta, misja i godzina startu po polsku.' },
    iss:         { name: 'ISS nad Polską', icon: 'satellite', src: 'CelesTrak', desc: 'Widoczne przeloty stacji w ciągu doby.', skip: true },
    satellites:  { name: 'Satelity na orbicie', icon: 'satellite-dish', src: 'CelesTrak', desc: 'Ile ich jest, czyje i ile to Starlinki.' },
    voyager:     { name: 'Gdzie są Voyagery', icon: 'navigation', src: 'NASA JPL', desc: 'Trajektorie sond od 1977 roku.' },
    planets:     { name: 'Układ planet dziś', icon: 'orbit', src: 'obliczenia (JPL)', desc: 'Położenia planet i najbliższe opozycje.' },
    solarCycle:  { name: 'Cykl słoneczny', icon: 'sun', src: 'NOAA SWPC', desc: 'Plamy na Słońcu i aktywność cyklu.' },
    flatEarth:   { name: 'Płaska Ziemia', icon: 'earth', src: '—', desc: 'Z przymrużeniem oka.' },
    asteroids:   { name: 'Asteroidy', icon: 'sparkle', src: 'NASA', desc: 'Obiekty mijające dziś Ziemię.' },
    weather:     { name: 'Pogoda w Polsce', icon: 'cloud-sun', src: 'Open-Meteo', desc: 'Prognoza na 12:00 dla dziewięciu miast.' },
    aurora:      { name: 'Zorza polarna', icon: 'sparkles', src: 'NOAA SWPC', desc: 'Szansa na zorzę nad Polską w nadchodzącą noc — poświata na mapie pogody.' },
    polishGames: { name: 'Polskie gry na Steamie', icon: 'gamepad-2', src: 'Steam / steamcharts', desc: 'Gracze, ceny i trend z 30 dni.' },
    planes:      { name: 'Samoloty nad Polską', icon: 'plane', src: 'adsb.fi', desc: 'Wszystkie wczorajsze loty na jednej mapie.', skip: true },
    mushrooms:   { name: 'Grzybobranie', icon: 'trees', src: 'Open-Meteo', desc: 'Szanse na grzyby w największych lasach.', skip: true },
    doctors:     { name: 'Kolejki do lekarza', icon: 'stethoscope', src: 'NFZ', desc: 'Średni czas oczekiwania na specjalistę w województwach — co dzień inna specjalność.' },
    avalanche:   { name: 'Lawiny w Tatrach', icon: 'mountain-snow', src: 'TOPR', desc: 'Stopień zagrożenia lawinowego — tylko zimą.', skip: true },
    trains:      { name: 'Opóźnienia pociągów', icon: 'train-front', src: 'PKP PLK', desc: 'Ile pociągów przyjechało wczoraj na czas, najbardziej spóźniony kurs z trasą na mapie i średnie spóźnienia na stacjach.' },
};

// Logotypy źródeł na taśmie: plik w logos/, tryb filtra (style.css) i wysokość — szerokie znaki niższe
const LOGOS = [
    ['nasa.svg', 'NASA', 'mono', 34], ['usgs.svg', 'USGS', 'gray', 30], ['worldbank.svg', 'Bank Światowy', 'mono', 30],
    ['economist.svg', 'The Economist', 'gray', 34], ['steam.svg', 'Steam', 'mono', 32], ['copernicus.png', 'Copernicus', 'mono', 34],
    ['noaa.svg', 'NOAA', 'gray', 38], ['nbp.svg', 'Narodowy Bank Polski', 'invert', 30], ['jpl.svg', 'NASA JPL', 'mono', 34],
    ['europeanunion.svg', 'Komisja Europejska / Eurostat', 'mono', 34], ['nsidc.svg', 'NSIDC', 'gray', 36], ['nordpool.svg', 'Nord Pool', 'mono', 30],
    ['nfz.svg', 'Narodowy Fundusz Zdrowia', 'mono', 32], ['plk.svg', 'PKP Polskie Linie Kolejowe', 'mono', 24],
];

// Wynik porannego runu: { date, complete, slides: [{ id, images, plugins, pool }], plugins: { id: { status, image, note, … } } }
// status: story (dziś na story) · ready (ma dane, dziś poza story) · empty (nie ma czego pokazać) · failed (źródło zawiodło)
let RUN = null;

const statusOf = (id) => RUN?.plugins[id]?.status ?? null;
const isToday = (id) => statusOf(id) === 'story';
const isFailed = (id) => ['empty', 'failed'].includes(statusOf(id));
const dayOf = (iso) => new Date(iso).toLocaleDateString('pl-PL', { day: 'numeric', month: 'long' });

// Kłopot z danymi pluginu — widoczny od razu na liście, nie dopiero po kliknięciu:
//   failed — API nie odpowiedziało i nie ma żadnych danych (BŁĄD)
//   empty  — źródło odpowiedziało, ale nie ma dziś nic do pokazania (BRAK DANYCH)
//   stale  — API nie odpowiedziało, pokazujemy ostatnie zapisane dane (ZAPAS)
function problem(id) {
    const run = RUN?.plugins[id];
    if (!run) return null;
    if (run.status === 'failed') return { kind: 'failed', flag: 'błąd', why: 'Źródło nie odpowiedziało — brak danych' };
    if (run.status === 'empty') return { kind: 'empty', flag: 'brak danych', why: run.note ?? 'Źródło nic dziś nie zwróciło' };
    if (run.stale) return { kind: 'stale', flag: 'zapas', why: `Błąd API — dane z ${run.fetchedAt ? dayOf(run.fetchedAt) : 'zapasu'}` };
    return null;
}
const hasProblem = (id) => problem(id) !== null;
const flagOf = (id) => problem(id)?.flag ?? (isToday(id) ? 'dziś' : '—');
const stateClass = (id) => [isFailed(id) ? 'failed' : isToday(id) ? 'today' : '', problem(id) ? `is-${problem(id).kind}` : ''].join(' ').trim();
const runSlide = (id) => RUN?.slides.find((s) => s.id === id);

async function loadRun() {
    try {
        const res = await fetch(`data/today.json?t=${Date.now()}`, { cache: 'no-store' });
        if (!res.ok) return;
        RUN = await res.json();
    } catch {
        return; // brak pliku (lokalny podgląd przed pierwszym runem) — strona bez statusów
    }

    // Pule slajdów z szablonów, nowe pluginy z JSON-a (bez opisu tutaj), wyłączone znikają — gdy run jest kompletny
    for (const slide of RUN.slides) {
        const local = SLIDES.find((s) => s.id === slide.id);
        if (local && slide.pool.length) local.plugins = slide.pool.filter((id) => RUN.plugins[id] || !RUN.complete);
    }
    for (const [id, p] of Object.entries(RUN.plugins)) {
        PLUGINS[id] ??= { name: p.label, icon: 'box', src: p.credit ?? '—', desc: '' };
        if (p.credit) PLUGINS[id].src = p.credit;
    }
    if (RUN.complete) {
        for (const id of Object.keys(PLUGINS)) if (!RUN.plugins[id]) delete PLUGINS[id];
    }
    // Plugin spoza puli szablonu (np. zorza doklejona do pogody) — dopisz do slajdu, na którym się pokazuje
    for (const [id, p] of Object.entries(RUN.plugins)) {
        const s = SLIDES.find((x) => x.id === p.slide);
        if (s && !s.plugins.includes(id)) s.plugins.push(id);
    }
}

const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => [...document.querySelectorAll(sel)];
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const slideOf = (id) => SLIDES.find((s) => s.plugins.includes(id));
const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
const icons = () => window.lucide?.createIcons();

// ------------------------------------------------------------------ render

function renderDate() {
    const day = RUN ? new Date(`${RUN.date}T12:00:00`) : new Date();
    const d = day.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long' });
    // Numer wydania = dzień roku — każde poranne story to kolejne wydanie biuletynu
    const issue = Math.floor((day - new Date(day.getFullYear(), 0, 0)) / 864e5);
    $('#issue').textContent = `Wydanie nr ${String(issue).padStart(3, '0')}`;
    $('#today-date').textContent = d;
}

// Karta w talii: prawdziwy render dzisiejszego slajdu, a bez niego — szkielet
function cardInner(s, i, total) {
    const img = runSlide(s.id)?.images[0];
    if (img) return `<img src="${esc(img)}" alt="" decoding="async">`;
    const heights = [40, 65, 52, 80, 58, 92, 70].map((h) => (h + i * 13) % 100 || 30);
    return `<div class="card-bars">${Array.from({ length: total }, (_, j) => `<i class="${j <= i ? 'on' : ''}"></i>`).join('')}</div>
        <div class="card-title">${esc(s.title)}</div>
        <div class="card-body"><span class="sk big"></span><span class="sk"></span><span class="sk w60"></span><span class="sk w40"></span></div>
        <div class="card-chart">${heights.map((h) => `<i style="height:${h}%"></i>`).join('')}</div>
        <div class="card-foot">swagnar.dev</div>`;
}

function renderDeck() {
    const live = SLIDES.filter((s) => !s.soon && (!RUN || runSlide(s.id)?.images.length));
    const mid = (live.length - 1) / 2;
    $('#deck').innerHTML = live.map((s, i) => {
        const k = i - mid;
        return `<a class="card${runSlide(s.id)?.images.length ? ' real' : ''}" href="#slide-${s.id}" data-cursor="${esc(s.title)}" style="--k:${k};--ak:${Math.abs(k)};--z:${live.length - i}" aria-label="Slajd ${esc(s.title)}">
            ${cardInner(s, i, live.length)}
        </a>`;
    }).join('');
}

function renderManifesto() {
    const el = $('#manifesto');
    const accent = new Set(['NASA', 'Steam', 'story.', 'mapy', 'wykresy']);
    el.innerHTML = el.textContent.trim().split(/\s+/)
        .map((w) => `<span class="w${accent.has(w.replace(/[—,]/g, '')) ? ' hl' : ''}">${esc(w)}</span>`).join(' ');
}

function renderSlides() {
    $('#slides').innerHTML = SLIDES.map((s, i) => {
        const shot = runSlide(s.id)?.images[0];
        const onStory = s.plugins.filter(isToday).length;
        const state = s.soon ? 'w przygotowaniu' : RUN && !shot ? 'dziś bez slajdu'
            : s.plugins.length ? (onStory ? `${onStory} z ${s.plugins.length} dziś` : `${s.plugins.length} w puli`) : 'stały slajd';
        return `
        <li class="slide${shot ? ' has-shot' : ''}" id="slide-${s.id}" data-scroll>
            <div class="slide-main">
                <div class="slide-top">
                    <span class="slide-n">${String(i + 1).padStart(2, '0')} / ${String(SLIDES.length).padStart(2, '0')}</span>
                    <span class="state">${state}</span>
                </div>
                <h3>${esc(s.title)}</h3>
                <p>${esc(s.desc)}</p>
                ${s.plugins.length ? `<div class="chips">${s.plugins.map((p) => `<span class="chip ${stateClass(p)}" data-flag="${esc(flagOf(p))}"><i data-lucide="${PLUGINS[p].icon}"></i>${esc(PLUGINS[p].name)}</span>`).join('')}</div>` : ''}
            </div>
            ${shot ? `<button class="slide-shot" data-shot="${s.id}" data-cursor="Powiększ" aria-label="Dzisiejszy slajd ${esc(s.title)} w pełnym rozmiarze"><img src="${esc(shot)}" alt="" loading="lazy" decoding="async"></button>` : ''}
        </li>`;
    }).join('');
}

let filter = 'all';
let query = '';

function renderFilters() {
    const groups = [
        { id: 'all', title: 'Wszystkie', n: Object.keys(PLUGINS).length },
        ...(RUN ? [{ id: 'today', title: 'Dziś', n: Object.keys(PLUGINS).filter(isToday).length }] : []),
        ...(RUN && Object.keys(PLUGINS).some(hasProblem) ? [{ id: 'problems', title: 'Problemy', n: Object.keys(PLUGINS).filter(hasProblem).length, warn: true }] : []),
        ...SLIDES.filter((s) => s.plugins.length).map((s) => ({ id: s.id, title: s.title[0] + s.title.slice(1).toLowerCase(), n: s.plugins.length })),
    ];
    $('#filters').innerHTML = groups.map((g) =>
        `<button class="seg${g.warn ? ' warn' : ''}" role="tab" data-filter="${g.id}" aria-selected="${g.id === filter}">${esc(g.title)}<span class="n">${g.n}</span></button>`).join('');
}

function renderPlugins() {
    const q = query.trim().toLowerCase();
    const ids = Object.keys(PLUGINS).filter((id) => {
        const p = PLUGINS[id];
        const inGroup = filter === 'all' || (filter === 'today' ? isToday(id) : filter === 'problems' ? hasProblem(id) : slideOf(id)?.id === filter);
        return inGroup && (!q || `${p.name} ${p.src} ${p.desc}`.toLowerCase().includes(q));
    });
    $('#plugins').innerHTML = ids.map((id, i) => {
        const p = PLUGINS[id];
        const trouble = problem(id);
        return `<li class="plugin-item" data-scroll style="--i:${i}"><button class="plugin ${stateClass(id)}" data-plugin="${id}" data-cursor="Szczegóły">
            <span class="p-no">${String(i + 1).padStart(2, '0')}</span>
            <span class="p-icon"><i data-lucide="${p.icon}"></i>${trouble ? '<i class="p-alert" data-lucide="triangle-alert"></i>' : ''}</span>
            <span class="p-name"><h3>${esc(p.name)}</h3>${trouble
                ? `<span class="p-why">${esc(trouble.why)}</span>`
                : `<span class="p-src">${esc(p.src)}</span>`}</span>
            <span class="p-slide">${esc(slideOf(id)?.title ?? '')}</span>
            <span class="flag">${esc(flagOf(id))}</span>
        </button></li>`;
    }).join('');
    $('#plugins-empty').hidden = ids.length > 0;
    icons();
    window.refreshScroll?.();
}

const timeOf = (iso) => new Date(iso).toLocaleString('pl-PL', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });

function openSheet(id) {
    const p = PLUGINS[id];
    const s = slideOf(id);
    const run = RUN?.plugins[id];
    const status = run?.status;
    const line = { story: 'dziś na story', ready: 'dziś poza story', empty: 'dziś brak danych', failed: 'dziś rano źródło nie odpowiedziało' }[status];
    const message = status === 'failed' ? 'Źródło nie zwróciło dziś danych.'
        : status === 'empty' ? (run.note ? `Dziś nie ma czego pokazać — ${run.note[0].toLowerCase()}${run.note.slice(1).replace(/\.$/, '')}.` : 'Dziś nie ma czego pokazać.')
        : RUN && !RUN.complete ? 'Render pojawi się za chwilę.'
        : RUN ? 'Ten plugin nie ma własnego bloku — dokleja się do innego.' : 'Podgląd bloku z porannego renderu.';
    const preview = run?.image
        ? `<img class="sheet-shot" src="${esc(run.image)}" alt="Blok ${esc(p.name)} z dzisiejszego renderu">`
        : `<div class="sheet-preview${isFailed(id) ? ' failed' : ''}">${esc(message)}</div>`;
    const trouble = problem(id);
    $('#sheet-body').innerHTML = `
        ${trouble ? `<p class="sheet-alert ${trouble.kind}"><i data-lucide="triangle-alert"></i><span><b>${esc(trouble.flag)}</b>${esc(trouble.why)}</span></p>` : ''}
        <div class="sheet-head ${stateClass(id)}">
            <span class="p-icon"><i data-lucide="${p.icon}"></i></span>
            <div><h3 id="sheet-title">${esc(p.name)}</h3><p>Slajd ${esc(s?.title ?? '—')}${line ? ` · <span class="${isFailed(id) ? 'fail-text' : ''}">${line}</span>` : ''}</p></div>
        </div>
        ${preview}
        <dl>
            ${p.desc ? `<dt>Co pokazuje</dt><dd>${esc(p.desc)}</dd>` : ''}
            <dt>Źródło</dt><dd>${esc(p.src)}</dd>
            ${run?.fetchedAt ? `<dt>Dane z</dt><dd>${esc(timeOf(run.fetchedAt))}${run.stale ? ' · <span class="fail-text">zapas, API dziś nie odpowiedziało</span>' : ''}</dd>` : ''}
            <dt>Kiedy</dt><dd>${p.skip ? 'tylko w dni, gdy ma co pokazać' : 'codziennie w puli losowania'}</dd>
        </dl>`;
    icons();
    $('#sheet').showModal();
}

function openShot(id) {
    const s = SLIDES.find((x) => x.id === id);
    const day = new Date(`${RUN.date}T12:00:00`).toLocaleDateString('pl-PL', { day: 'numeric', month: 'long' });
    $('#sheet-body').innerHTML = `
        <div class="sheet-head"><div><h3 id="sheet-title">${esc(s.title)}</h3><p>Dzisiejszy slajd · ${esc(day)}</p></div></div>
        ${(runSlide(id)?.images ?? []).map((src) => `<img class="sheet-story" src="${esc(src)}" alt="Slajd ${esc(s.title)}">`).join('')}`;
    $('#sheet').showModal();
}

function renderCredits() {
    const sources = [...new Set(Object.values(PLUGINS).map((p) => p.src).filter((s) => s !== '—'))];
    $('#credits').textContent = sources.join(' · ');
    // Taśma: dwa rzędy logotypów w przeciwnych kierunkach, powtórzone, żeby nie było widać końca
    const row = (list) => [...list, ...list, ...list].map(([file, alt, mode, h]) =>
        `<img class="logo ${mode}" src="logos/${file}" alt="${esc(alt)}" style="--h:${h}px" loading="lazy">`).join('');
    $('#ticker-1').innerHTML = row(LOGOS.slice(0, 6));
    $('#ticker-2').innerHTML = row(LOGOS.slice(6));
}

// ------------------------------------------------------------------ efekty przewijania

const clamp = (v) => Math.min(1, Math.max(0, v));

function scrollEffects() {
    const root = document.documentElement;
    const hero = $('.hero');
    const words = $$('#manifesto .w');
    const manifesto = $('.manifesto');
    const timeline = $('#timeline');
    const topbar = $('#topbar');
    const tabbar = $('.tabbar');
    const segments = $$('#progress span');
    const marks = ['top', 'slajdy', 'pluginy', 'jak'].map((id) => document.getElementById(id));
    let lastY = scrollY;
    let ticking = false;

    const update = () => {
        ticking = false;
        const y = scrollY;
        const vh = innerHeight;
        const page = clamp(y / (root.scrollHeight - vh));
        root.style.setProperty('--scroll', page.toFixed(4));
        // Segment n wypełnia się od początku sekcji n do początku następnej (ostatni — do końca strony)
        const tops = marks.map((m) => m.getBoundingClientRect().top + y);
        segments.forEach((seg, i) => {
            const end = i + 1 < tops.length ? tops[i + 1] : root.scrollHeight - vh;
            seg.style.setProperty('--f', clamp((y - tops[i] + 1) / Math.max(1, end - tops[i])).toFixed(3));
        });
        topbar.classList.toggle('scrolled', y > 8);

        // Talia rozkłada się, gdy hero odjeżdża — pełny wachlarz po ~60% wysokości hero
        hero.style.setProperty('--p', reduced ? 1 : clamp(y / (hero.offsetHeight * .6)).toFixed(4));

        // Manifest: słowo po słowie, od wejścia sekcji do jej środka
        const r = manifesto.getBoundingClientRect();
        const lit = Math.round(clamp((vh * .85 - r.top) / (r.height * .9)) * words.length);
        words.forEach((w, i) => w.classList.toggle('on', reduced || i < lit));

        // Każdy [data-scroll]: --e = wejście od dołu ekranu (0 → 1 na pierwszych 35% wysokości ekranu),
        // --v = droga przez cały ekran (0 = wchodzi dołem, 1 = wychodzi górą). Poza ekranem nic nie liczymy.
        if (!reduced) {
            for (const el of document.querySelectorAll('[data-scroll]')) {
                const b = el.getBoundingClientRect();
                if (b.bottom < -200 || b.top > vh + 200) continue;
                el.style.setProperty('--e', clamp((vh - b.top) / (vh * .35)).toFixed(3));
                el.style.setProperty('--v', clamp((vh - b.top) / (vh + b.height)).toFixed(3));
                if (el.parentElement === timeline) el.classList.toggle('lit', b.top < vh * .6);
            }
        }

        // Oś czasu rysuje się, gdy przewija się przez środek ekranu
        const t = timeline.getBoundingClientRect();
        timeline.style.setProperty('--t', reduced ? 1 : clamp((vh * .7 - t.top) / t.height));

        // Pasek dolny chowa się przy przewijaniu w dół, wraca przy przewijaniu w górę
        tabbar.style.setProperty('--hide', y > lastY && y > vh ? '140%' : '0');
        lastY = y;
    };
    const request = () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } };
    addEventListener('scroll', request, { passive: true });
    addEventListener('resize', request);
    // Po filtrowaniu pluginów nowe kafelki też muszą dostać wartości od razu
    window.refreshScroll = request;
    update();
}

function activeNav() {
    const links = $$('.tabbar a, .topnav a');
    const io = new IntersectionObserver((entries) => entries.filter((e) => e.isIntersecting).forEach((e) => {
        links.forEach((a) => a.classList.toggle('active', a.getAttribute('href') === `#${e.target.id}`));
    }), { rootMargin: '-45% 0px -50% 0px' });
    ['top', 'slajdy', 'pluginy', 'jak'].forEach((id) => io.observe(document.getElementById(id)));
}

// Zegar w pasku: czas warszawski — o 6:00 tej strefy wychodzi story
function clock() {
    const el = $('#clock');
    const fmt = new Intl.DateTimeFormat('pl-PL', { timeZone: 'Europe/Warsaw', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    const box = $('#countdown');
    const left = $('#countdown-time');
    const two = (n) => String(n).padStart(2, '0');
    const tick = () => {
        const now = new Date();
        el.textContent = `WAW ${fmt.format(now)}`;
        // Sekundy do najbliższej 06:00 czasu warszawskiego (zmiana czasu przesuwa najwyżej o godzinę — tu bez znaczenia)
        const [h, m, s] = fmt.format(now).split(':').map(Number);
        const secs = (6 * 3600 - (h * 3600 + m * 60 + s) + 86400) % 86400 || 86400;
        left.textContent = `${two(Math.floor(secs / 3600))}:${two(Math.floor(secs / 60) % 60)}:${two(secs % 60)}`;
        box.style.setProperty('--f', (1 - secs / 86400).toFixed(4));
        box.hidden = false;
    };
    tick();
    setInterval(tick, 1000);
}

// Kursor: krzyżyk w punkcie wskaźnika + ramka ze znaczników cięcia, która dojeżdża z opóźnieniem.
// Podpis pokazuje współrzędne na stronie, a nad linkiem/przyciskiem — co się stanie po kliknięciu (data-cursor).
function customCursor() {
    if (!matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    const el = document.createElement('div');
    el.className = 'cursor off';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = '<span class="cursor-dot"></span><span class="cursor-frame"></span><span class="cursor-label"></span>';
    document.body.append(el);
    document.documentElement.classList.add('cursor-on');
    const label = el.querySelector('.cursor-label');

    let x = -99, y = -99, fx = x, fy = y, raf = 0, action = null;
    const pad = (n) => String(Math.max(0, Math.round(n))).padStart(4, '0');
    const text = () => { label.textContent = action ?? `x ${pad(x)} · y ${pad(scrollY + y)}`; };
    const follow = () => {
        const k = reduced ? 1 : .2;
        fx += (x - fx) * k;
        fy += (y - fy) * k;
        el.style.setProperty('--fx', `${fx.toFixed(1)}px`);
        el.style.setProperty('--fy', `${fy.toFixed(1)}px`);
        raf = Math.abs(x - fx) + Math.abs(y - fy) > .3 ? requestAnimationFrame(follow) : 0;
    };

    addEventListener('pointermove', (e) => {
        if (e.pointerType !== 'mouse') return;
        x = e.clientX;
        y = e.clientY;
        el.style.setProperty('--x', `${x}px`);
        el.style.setProperty('--y', `${y}px`);
        el.classList.remove('off');

        const target = e.target.closest?.('[data-cursor], a, button');
        action = target ? target.dataset.cursor ?? (target.tagName === 'A' ? 'Idź ›' : 'Kliknij') : null;
        el.classList.toggle('active', !!target);
        el.classList.toggle('typing', !!e.target.closest?.('input'));
        text();
        if (!raf) raf = requestAnimationFrame(follow);
    }, { passive: true });
    addEventListener('scroll', () => action ?? text(), { passive: true });
    addEventListener('pointerdown', () => el.classList.add('down'));
    addEventListener('pointerup', () => el.classList.remove('down'));
    document.documentElement.addEventListener('mouseleave', () => el.classList.add('off'));
    // Arkusz leży w warstwie nad stroną (top layer) — na czas otwarcia własny kursor znika, wraca zwykły
    const sheet = $('#sheet');
    new MutationObserver(() => el.classList.toggle('off', sheet.open)).observe(sheet, { attributes: true, attributeFilter: ['open'] });
}

// ------------------------------------------------------------------ zdarzenia

$('#filters').addEventListener('click', (e) => {
    const b = e.target.closest('[data-filter]');
    if (!b) return;
    filter = b.dataset.filter;
    renderFilters();
    renderPlugins();
});
$('#search').addEventListener('input', (e) => { query = e.target.value; renderPlugins(); });
$('#plugins').addEventListener('click', (e) => {
    const b = e.target.closest('[data-plugin]');
    if (b) openSheet(b.dataset.plugin);
});
$('#slides').addEventListener('click', (e) => {
    const b = e.target.closest('[data-shot]');
    if (b) openShot(b.dataset.shot);
});
$('#sheet-close').addEventListener('click', () => $('#sheet').close());
$('#sheet').addEventListener('click', (e) => { if (e.target === e.currentTarget) $('#sheet').close(); });

function render() {
    renderDate();
    renderDeck();
    renderSlides();
    renderFilters();
    renderPlugins();
    renderCredits();
    icons();
}

renderManifesto();
render();
activeNav();
scrollEffects();
clock();
customCursor();
// Dane z porannego runu dochodzą po pierwszym renderze — strona nie czeka na fetch
loadRun().then(() => RUN && render());
