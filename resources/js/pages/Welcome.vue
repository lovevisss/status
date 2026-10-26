<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Activity,
    ArrowUpRight,
    CalendarDays,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock3,
    ExternalLink,
    Search,
    ShieldCheck,
    WifiOff,
    X,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';

type DayRecord = {
    state: 'up' | 'degraded' | 'down' | 'maintenance';
    count: number;
    maintenance: boolean;
};
type Service = {
    key: string;
    name: string;
    description: string;
    url: string;
    state: 'up' | 'down' | 'pending' | 'unknown' | 'maintenance';
    is_up: boolean;
    maintenance: boolean;
    status_code: number | null;
    response_ms: number | null;
    error: string | null;
    checked_at: string | null;
    uptime: number | null;
    has_gaps: boolean;
    days: Record<string, DayRecord>;
};
type Incident = {
    key: string;
    service: string;
    confirmed_at: string;
    recovered_at: string | null;
    closed_at: string | null;
};
type Maintenance = {
    id: number;
    title: string;
    description: string;
    starts_at: string;
    ends_at: string;
    status: 'planned' | 'active' | 'completed';
    service_ids: number[];
    service_names: string[];
};
type StatusData = {
    services: Service[];
    incidents: Incident[];
    maintenance: Maintenance[];
    lastCheckedAt: string | null;
    historyDays: number;
    canManage?: boolean;
};
const props = defineProps<StatusData>();
const data = ref<StatusData>({
    services: props.services,
    incidents: props.incidents,
    maintenance: props.maintenance,
    lastCheckedAt: props.lastCheckedAt,
    historyDays: props.historyDays,
});
const monthOffset = ref(0);
const selectedDay = ref<string | null>(null);
const serviceSearch = ref('');
let timer: ReturnType<typeof setInterval> | undefined;
const today = new Date();
today.setHours(12, 0, 0, 0);
const dateKey = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const displayDate = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('zh-CN', {
              timeZone: 'Asia/Shanghai',
              month: 'long',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          }).format(new Date(value))
        : '尚未检测';
const visibleDays = computed(() =>
    Array.from({ length: 30 }, (_, i) => {
        const d = new Date(today);
        d.setDate(d.getDate() - 29 + i);
        return dateKey(d);
    }),
);
const upCount = computed(
    () => data.value.services.filter((s) => s.is_up).length,
);
const downCount = computed(
    () => data.value.services.filter((s) => s.state === 'down').length,
);
const pendingCount = computed(
    () => data.value.services.filter((s) => s.state === 'pending').length,
);
const unknownCount = computed(
    () => data.value.services.filter((s) => s.state === 'unknown').length,
);
const maintenanceCount = computed(
    () => data.value.services.filter((s) => s.maintenance).length,
);
const visibleMaintenance = computed(() =>
    data.value.maintenance.filter((item) => item.status !== 'completed'),
);
const maintenanceHistory = computed(() =>
    data.value.maintenance.filter((item) => item.status === 'completed'),
);
const overall = computed(() =>
    downCount.value
        ? 'down'
        : unknownCount.value
          ? 'unknown'
          : pendingCount.value
            ? 'pending'
            : maintenanceCount.value
              ? 'maintenance'
              : 'up',
);
const averageUptime = computed(() => {
    const a = data.value.services
        .map((s) => s.uptime)
        .filter((n): n is number => n !== null);
    return a.length
        ? (a.reduce((x, y) => x + y, 0) / a.length).toFixed(2)
        : '—';
});
const filteredServices = computed(() => {
    const query = serviceSearch.value.trim().toLocaleLowerCase();
    if (!query) return data.value.services;

    return data.value.services.filter((service) => {
        const hostname = new URL(service.url).hostname.toLocaleLowerCase();
        return (
            hostname.includes(query) ||
            service.name.toLocaleLowerCase().includes(query)
        );
    });
});
const monthDate = computed(
    () =>
        new Date(
            today.getFullYear(),
            today.getMonth() + monthOffset.value,
            1,
            12,
        ),
);
const monthLabel = computed(
    () =>
        `${monthDate.value.getFullYear()}年 ${monthDate.value.getMonth() + 1}月`,
);
const calendar = computed(() => {
    const y = monthDate.value.getFullYear(),
        m = monthDate.value.getMonth();
    const offset = (new Date(y, m, 1).getDay() + 6) % 7;
    const count = new Date(y, m + 1, 0).getDate();
    return Array.from(
        { length: Math.ceil((offset + count) / 7) * 7 },
        (_, i) => {
            const d = new Date(y, m, i - offset + 1, 12);
            return {
                key: dateKey(d),
                number: d.getDate(),
                current: d.getMonth() === m,
                future: d > today,
            };
        },
    );
});
const dayDetails = computed(() =>
    selectedDay.value
        ? data.value.services
              .map((service) => ({
                  service,
                  record: service.days[selectedDay.value!],
              }))
              .filter((x) => x.record)
        : [],
);
function dayState(
    date: string,
): 'up' | 'degraded' | 'down' | 'maintenance' | 'unknown' {
    const r = data.value.services.map((s) => s.days[date]).filter(Boolean);
    if (!r.length) return 'unknown';
    if (r.some((x) => x.state === 'down')) return 'down';
    if (r.some((x) => x.state === 'degraded')) return 'degraded';
    if (r.some((x) => x.state === 'maintenance')) return 'maintenance';
    return 'up';
}
const dayHasMaintenance = (date: string) =>
    data.value.services.some((service) => service.days[date]?.maintenance);
async function loadStatus() {
    try {
        const r = await fetch('/api/status', {
            headers: { Accept: 'application/json' },
        });
        if (r.ok) data.value = await r.json();
    } catch {
        /* Retain latest data. */
    }
}
onMounted(() => {
    timer = setInterval(loadStatus, 120000);
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head title="校内服务状态" />
    <div class="status-page">
        <div class="top-line"></div>
        <div class="site-shell">
            <header class="site-header">
                <div class="brand-lockup">
                    <div class="brand-mark">
                        <Activity :size="24" :stroke-width="2.5" />
                    </div>
                    <div>
                        <div class="brand-title">
                            校园服务状态<span class="brand-dot">.</span>
                        </div>
                        <div class="brand-subtitle">CAMPUS SERVICE STATUS</div>
                    </div>
                </div>
                <div class="header-right">
                    <a
                        v-if="props.canManage"
                        class="admin-link"
                        href="/admin/monitors"
                        >管理监测</a
                    >
                    <span class="header-live"
                        ><span class="live-pulse"></span> 每 2
                        分钟自动探测</span
                    >
                </div>
            </header>
            <main>
                <div class="intro-row">
                    <div>
                        <div class="eyebrow">
                            <span class="eyebrow-line"></span> CAMPUS NETWORK
                            MONITOR
                        </div>
                        <h1>每一项服务，<br /><em>都清晰可见。</em></h1>
                        <p class="intro-copy">
                            持续追踪校内常用网站的可访问性，让服务状态一目了然。
                        </p>
                    </div>
                    <div class="intro-side">
                        <span>最后检测时间</span
                        ><strong>{{ displayDate(data.lastCheckedAt) }}</strong
                        ><span class="timezone">北京时间 · UTC+8</span>
                    </div>
                </div>
                <section
                    class="overview"
                    :class="`overview-${overall}`"
                    aria-label="整体运行状态"
                >
                    <div class="overview-icon">
                        <Check
                            v-if="overall === 'up'"
                            :size="25"
                            :stroke-width="2.5"
                        /><WifiOff
                            v-else-if="overall === 'down'"
                            :size="25"
                        /><Clock3 v-else :size="25" />
                    </div>
                    <div class="overview-text">
                        <span class="overline">当前状态 · SYSTEM OVERVIEW</span>
                        <h2>
                            {{
                                overall === 'up'
                                    ? '所有服务运行正常'
                                    : overall === 'down'
                                      ? '部分服务访问异常'
                                      : overall === 'pending'
                                        ? '部分服务待复核'
                                        : overall === 'maintenance'
                                          ? '部分服务计划维护中'
                                          : '监测中断／状态未知'
                            }}
                        </h2>
                        <p>
                            {{
                                overall === 'up'
                                    ? '所有已监测的校内服务均可正常访问。'
                                    : overall === 'down'
                                      ? `${downCount} 项服务已确认访问异常，请查看下方详情。`
                                      : overall === 'pending'
                                        ? `${pendingCount} 项服务首次探测失败，正在等待下一次检测。`
                                        : overall === 'maintenance'
                                          ? `${maintenanceCount} 项服务处于计划维护时段，探测仍在继续。`
                                          : `${unknownCount} 项服务超过 5 分钟没有新的探测记录。`
                            }}
                        </p>
                    </div>
                    <div class="overview-stamp">
                        <ShieldCheck :size="18" /><span
                            >{{ upCount }} / {{ data.services.length
                            }}<small>服务正常</small></span
                        >
                    </div>
                </section>
                <section
                    v-if="visibleMaintenance.length"
                    class="maintenance-section"
                    aria-label="维护预告"
                >
                    <div class="maintenance-heading">
                        <CalendarDays :size="20" />
                        <div>
                            <span>MAINTENANCE NOTICE</span>
                            <h2>计划维护预告</h2>
                        </div>
                    </div>
                    <article
                        v-for="window in visibleMaintenance"
                        :key="window.id"
                        class="maintenance-notice"
                    >
                        <span class="maintenance-status">{{
                            window.status === 'active' ? '维护中' : '即将维护'
                        }}</span>
                        <div>
                            <h3>{{ window.title }}</h3>
                            <p>{{ window.description }}</p>
                            <p class="maintenance-meta">
                                受影响服务：{{
                                    window.service_names.join('、')
                                }}
                            </p>
                        </div>
                        <time
                            >{{ displayDate(window.starts_at) }} —
                            {{ displayDate(window.ends_at) }} · 北京时间</time
                        >
                    </article>
                </section>
                <div class="metric-row">
                    <div class="metric">
                        <span class="metric-label">监测服务</span
                        ><strong
                            >{{ data.services.length }}<small>项</small></strong
                        ><span class="metric-foot">持续关注校园网络</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">当前可访问</span
                        ><strong>{{ upCount }}<small>项</small></strong
                        ><span class="metric-foot"
                            ><span class="mini-dot"></span> 最近一次检测</span
                        >
                    </div>
                    <div class="metric">
                        <span class="metric-label">近 30 天平均可用率</span
                        ><strong
                            >{{ averageUptime
                            }}<small v-if="averageUptime !== '—'"
                                >%</small
                            ></strong
                        ><span class="metric-foot"
                            >仅统计实际探测；缺失时段不计入</span
                        >
                    </div>
                </div>
                <section class="services-section">
                    <div class="section-heading">
                        <div>
                            <div class="eyebrow section-eyebrow">
                                01 / SERVICE HEALTH
                            </div>
                            <h2>服务运行状态</h2>
                        </div>
                        <div class="service-tools">
                            <span class="section-note"
                                >最近 30 天的每日检测记录</span
                            >
                            <label class="service-search">
                                <Search :size="17" aria-hidden="true" />
                                <input
                                    v-model="serviceSearch"
                                    type="search"
                                    autocomplete="off"
                                    aria-label="按域名或服务名称查找"
                                    placeholder="搜索域名或服务名称"
                                />
                                <button
                                    v-if="serviceSearch"
                                    type="button"
                                    aria-label="清空搜索"
                                    @click="serviceSearch = ''"
                                >
                                    <X :size="15" />
                                </button>
                            </label>
                        </div>
                    </div>
                    <p class="search-count" aria-live="polite">
                        {{
                            serviceSearch.trim()
                                ? `找到 ${filteredServices.length} / ${data.services.length} 项服务`
                                : `共 ${data.services.length} 项服务`
                        }}
                    </p>
                    <div class="services-list">
                        <article
                            v-for="(service, index) in filteredServices"
                            :key="service.key"
                            class="service-card"
                        >
                            <div class="service-top">
                                <div class="service-identity">
                                    <span class="service-index"
                                        >0{{ index + 1 }}</span
                                    >
                                    <div class="service-name-wrap">
                                        <div class="service-title-line">
                                            <span
                                                class="status-indicator"
                                                :class="service.state"
                                            ></span>
                                            <h3>{{ service.name }}</h3>
                                            <span
                                                class="state-tag"
                                                :class="service.state"
                                                >{{
                                                    service.state === 'up'
                                                        ? '运行正常'
                                                        : service.state ===
                                                            'down'
                                                          ? '访问异常'
                                                          : service.state ===
                                                              'maintenance'
                                                            ? '计划维护中'
                                                            : service.state ===
                                                                'pending'
                                                              ? '可能异常／待复核'
                                                              : '监测中断／状态未知'
                                                }}</span
                                            >
                                            <span
                                                v-if="
                                                    service.maintenance &&
                                                    service.state !==
                                                        'maintenance'
                                                "
                                                class="maintenance-inline"
                                                >维护时段</span
                                            >
                                        </div>
                                        <p>{{ service.description }}</p>
                                    </div>
                                </div>
                                <div class="service-rate">
                                    <strong>{{
                                        service.uptime === null
                                            ? '—'
                                            : `${service.uptime.toFixed(2)}%`
                                    }}</strong
                                    ><span
                                        >近 30 天可用率{{
                                            service.has_gaps
                                                ? ' · 有缺失时段'
                                                : ''
                                        }}</span
                                    >
                                </div>
                            </div>
                            <div
                                class="service-bar"
                                role="img"
                                :aria-label="`${service.name}最近30天状态`"
                            >
                                <span
                                    v-for="date in visibleDays"
                                    :key="date"
                                    class="bar-day"
                                    :class="[
                                        service.days[date]?.state ?? 'unknown',
                                        service.days[date]?.maintenance
                                            ? 'has-maintenance'
                                            : '',
                                    ]"
                                    :title="`${date} · ${service.days[date] ? { up: '正常', degraded: '部分异常', down: '异常', maintenance: '计划维护' }[service.days[date].state] + ` · ${service.days[date].count}次检测${service.days[date].maintenance ? ' · 含维护时段' : ''}` : '暂无数据'}`"
                                ></span>
                            </div>
                            <div class="bar-labels">
                                <span>30 天前</span><span>今天</span>
                            </div>
                            <div class="service-footer">
                                <a
                                    :href="service.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    >{{
                                        service.url.replace(/^https?:\/\//, '')
                                    }}
                                    <ExternalLink :size="13" /></a
                                ><span>{{
                                    service.checked_at
                                        ? `最近响应 ${service.response_ms} ms${service.status_code ? ` · HTTP ${service.status_code}` : ''}`
                                        : '尚无检测数据'
                                }}</span>
                            </div>
                            <div
                                v-if="
                                    (service.state === 'down' ||
                                        service.state === 'pending') &&
                                    service.error
                                "
                                class="service-error"
                            >
                                {{ service.error }}
                            </div>
                        </article>
                    </div>
                    <div
                        v-if="filteredServices.length === 0"
                        class="search-empty"
                    >
                        <Search :size="23" aria-hidden="true" />
                        <strong>没有找到匹配的服务</strong>
                        <span>试试输入其他域名片段或服务名称。</span>
                        <button type="button" @click="serviceSearch = ''">
                            清空搜索
                        </button>
                    </div>
                    <div class="legend">
                        <span><i class="legend-up"></i> 正常</span
                        ><span><i class="legend-degraded"></i> 部分异常</span
                        ><span><i class="legend-down"></i> 异常</span
                        ><span><i class="legend-unknown"></i> 暂无数据</span>
                        <span><i class="legend-maintenance"></i> 计划维护</span>
                    </div>
                </section>
                <div class="lower-grid">
                    <section>
                        <div class="section-heading compact">
                            <div>
                                <div class="eyebrow section-eyebrow">
                                    02 / ACTIVITY CALENDAR
                                </div>
                                <h2>事件日历</h2>
                            </div>
                            <CalendarDays :size="21" class="heading-icon" />
                        </div>
                        <div class="calendar-card">
                            <div class="calendar-head">
                                <button
                                    type="button"
                                    aria-label="上个月"
                                    :disabled="monthOffset <= -2"
                                    @click="
                                        monthOffset--;
                                        selectedDay = null;
                                    "
                                >
                                    <ChevronLeft :size="18" /></button
                                ><strong>{{ monthLabel }}</strong
                                ><button
                                    type="button"
                                    aria-label="下个月"
                                    :disabled="monthOffset >= 0"
                                    @click="
                                        monthOffset++;
                                        selectedDay = null;
                                    "
                                >
                                    <ChevronRight :size="18" />
                                </button>
                            </div>
                            <div class="calendar-grid weekdays">
                                <span
                                    v-for="day in [
                                        '一',
                                        '二',
                                        '三',
                                        '四',
                                        '五',
                                        '六',
                                        '日',
                                    ]"
                                    :key="day"
                                    >{{ day }}</span
                                >
                            </div>
                            <div class="calendar-grid dates">
                                <button
                                    v-for="day in calendar"
                                    :key="day.key"
                                    type="button"
                                    :class="[
                                        day.current ? '' : 'outside',
                                        dayState(day.key),
                                        dayHasMaintenance(day.key)
                                            ? 'has-maintenance'
                                            : '',
                                        selectedDay === day.key
                                            ? 'selected'
                                            : '',
                                    ]"
                                    :disabled="!day.current || day.future"
                                    :aria-label="`${day.key} ${dayState(day.key)}`"
                                    @click="selectedDay = day.key"
                                >
                                    <span>{{ day.number }}</span>
                                </button>
                            </div>
                            <div class="calendar-detail">
                                <template v-if="selectedDay"
                                    ><strong>{{ selectedDay }}</strong
                                    ><span v-if="dayDetails.length">{{
                                        dayDetails
                                            .map(
                                                (x) =>
                                                    `${x.service.name}：${x.record.state === 'maintenance' ? '计划维护' : x.record.state === 'up' ? '正常' : '出现异常'}${x.record.maintenance && x.record.state !== 'maintenance' ? '（含维护）' : ''}`,
                                            )
                                            .join(' · ')
                                    }}</span
                                    ><span v-else
                                        >该日暂无检测记录</span
                                    ></template
                                ><template v-else
                                    ><span
                                        >选择日期查看当日服务状态</span
                                    ></template
                                >
                            </div>
                        </div>
                    </section>
                    <section>
                        <div class="section-heading compact">
                            <div>
                                <div class="eyebrow section-eyebrow">
                                    03 / INCIDENT LOG
                                </div>
                                <h2>异常记录</h2>
                            </div>
                            <ArrowUpRight :size="21" class="heading-icon" />
                        </div>
                        <div class="events-card">
                            <template v-if="data.incidents.length"
                                ><a
                                    v-for="incident in data.incidents"
                                    :key="incident.key"
                                    class="event-row"
                                    :href="`/incidents/${encodeURIComponent(incident.key)}`"
                                    :aria-label="`查看${incident.service}异常详情`"
                                >
                                    <span class="event-mark"></span>
                                    <div>
                                        <strong
                                            >{{
                                                incident.service
                                            }}确认访问异常</strong
                                        ><span>{{
                                            incident.recovered_at
                                                ? `已于 ${displayDate(incident.recovered_at)} 恢复`
                                                : incident.closed_at
                                                  ? `监测已于 ${displayDate(incident.closed_at)} 终止`
                                                  : '故障持续中'
                                        }}</span>
                                    </div>
                                    <time>{{
                                        displayDate(incident.confirmed_at)
                                    }}</time>
                                </a></template
                            >
                            <div v-else class="empty-events">
                                <div class="empty-icon">
                                    <ShieldCheck :size="25" />
                                </div>
                                <strong>暂无异常记录</strong>
                                <p>最近 30 天尚无确认的服务故障。</p>
                                <span>历史记录会随定时检测逐步积累</span>
                            </div>
                        </div>
                    </section>
                </div>
                <section
                    v-if="maintenanceHistory.length"
                    class="maintenance-history"
                >
                    <h2>近期维护记录</h2>
                    <div v-for="window in maintenanceHistory" :key="window.id">
                        <strong>{{ window.title }}</strong
                        ><span
                            >{{ window.service_names.join('、') }} ·
                            {{ displayDate(window.starts_at) }} —
                            {{ displayDate(window.ends_at) }}</span
                        >
                    </div>
                </section>
            </main>
            <footer class="site-footer">
                <div class="footer-brand">
                    <Activity :size="18" /> 校园服务状态
                </div>
                <span>数据仅反映本站校内探测节点的访问结果。</span
                ><span>每 2 分钟检测一次 · 自动保留 30 天</span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;600;700;800&display=swap');
.status-page {
    --ink: #18302b;
    --muted: #80908a;
    --line: #e5eae5;
    min-height: 100vh;
    background: #f9faf7;
    color: var(--ink);
    font-family: 'Noto Sans SC', sans-serif;
    font-size: 14px;
}
.top-line {
    height: 5px;
    background: linear-gradient(90deg, #123b32, #41936c 72%, #d4e6ad);
}
.site-shell {
    max-width: 1160px;
    margin: auto;
    padding: 0 32px;
}
.site-header {
    height: 104px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--line);
}
.brand-lockup {
    display: flex;
    align-items: center;
    gap: 12px;
}
.brand-mark {
    width: 43px;
    height: 43px;
    background: #173f35;
    color: #fff;
    border-radius: 12px;
    display: grid;
    place-items: center;
}
.brand-title {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.04em;
    line-height: 1.1;
}
.brand-dot {
    color: #4eaa76;
}
.brand-subtitle {
    font:
        10px 'DM Mono',
        monospace;
    letter-spacing: 0.17em;
    color: #94a39c;
    margin-top: 6px;
}
.header-right {
    display: flex;
    align-items: center;
    gap: 24px;
}
.header-live {
    font-size: 12px;
    color: #73847c;
    display: flex;
    align-items: center;
    gap: 8px;
}
.live-pulse {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #32a66b;
    box-shadow: 0 0 0 4px #e6f3e9;
}
.refresh-button {
    border: 1px solid #dce5dc;
    background: #fff;
    color: #315a48;
    border-radius: 9px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    transition: 0.2s;
}
.refresh-button:hover {
    background: #eaf3ed;
}
.refresh-button:disabled {
    opacity: 0.65;
}
.spinning {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
.intro-row {
    display: flex;
    justify-content: space-between;
    align-items: end;
    padding: 65px 0 38px;
}
.eyebrow {
    font:
        11px 'DM Mono',
        monospace;
    letter-spacing: 0.18em;
    font-weight: 500;
    color: #378065;
    display: flex;
    align-items: center;
    gap: 10px;
}
.eyebrow-line {
    width: 19px;
    height: 1px;
    background: #378065;
}
.intro-row h1 {
    font-size: clamp(38px, 5.2vw, 60px);
    line-height: 1.24;
    letter-spacing: -0.075em;
    margin: 21px 0 17px;
    font-weight: 800;
}
.intro-row h1 em {
    font-style: normal;
    color: #4c926a;
}
.intro-copy {
    color: #81918a;
    font-size: 14px;
    margin: 0;
}
.intro-side {
    text-align: right;
    padding-bottom: 5px;
    display: flex;
    flex-direction: column;
    gap: 5px;
    color: #87958c;
    font-size: 12px;
}
.intro-side strong {
    font-size: 15px;
    color: #344d42;
}
.timezone {
    font:
        11px 'DM Mono',
        monospace;
    color: #abb5ae;
}
.overview {
    background: #e5f4e8;
    border: 1px solid #d2e9d8;
    border-radius: 16px;
    min-height: 137px;
    padding: 25px 30px;
    display: flex;
    align-items: center;
    gap: 20px;
    position: relative;
    overflow: hidden;
}
.overview:after {
    content: '';
    width: 270px;
    height: 270px;
    border: 1px solid rgba(52, 135, 84, 0.1);
    border-radius: 50%;
    position: absolute;
    right: 105px;
    top: -190px;
}
.overview-down {
    background: #fff1ea;
    border-color: #f6d9c9;
}
.overview-unknown {
    background: #edf1ed;
    border-color: #e0e6df;
}
.overview-icon {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #2b9566;
    color: #fff;
    display: grid;
    place-items: center;
    flex: none;
}
.overview-down .overview-icon {
    background: #d96847;
}
.overview-unknown .overview-icon {
    background: #82918a;
}
.overline {
    font:
        10px 'DM Mono',
        monospace;
    letter-spacing: 0.15em;
    color: #48916a;
}
.overview-text h2 {
    font-size: 23px;
    letter-spacing: -0.04em;
    margin: 7px 0 2px;
}
.overview-text p {
    margin: 0;
    color: #688575;
    font-size: 13px;
}
.overview-stamp {
    margin-left: auto;
    color: #2a7454;
    display: flex;
    align-items: center;
    gap: 11px;
    border-left: 1px solid #c7e2ce;
    padding-left: 27px;
    z-index: 1;
}
.overview-stamp > span {
    font:
        700 25px 'DM Mono',
        monospace;
    line-height: 1;
}
.overview-stamp small {
    display: block;
    font:
        11px 'Noto Sans SC',
        sans-serif;
    margin-top: 7px;
    color: #72927d;
}
.check-error {
    color: #a74932;
    margin: 12px 0 0;
}
.metric-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin: 17px 0 65px;
}
.metric {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 22px 25px;
    min-height: 138px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 5px 20px rgba(29, 67, 49, 0.025);
}
.metric-label {
    font-size: 12px;
    color: #839189;
}
.metric strong {
    font:
        500 32px 'DM Mono',
        monospace;
    letter-spacing: -0.07em;
    margin: 10px 0 5px;
}
.metric strong small {
    font:
        12px 'Noto Sans SC',
        sans-serif;
    margin-left: 4px;
    color: #7c8f80;
    letter-spacing: 0;
}
.metric-foot {
    color: #a0aba2;
    font-size: 11px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.mini-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #31a66b;
}
.section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    margin-bottom: 20px;
}
.section-eyebrow {
    font-size: 10px;
    color: #7da489;
}
.section-heading h2 {
    font-size: 26px;
    letter-spacing: -0.05em;
    margin: 8px 0 0;
}
.section-note {
    font-size: 12px;
    color: #9aa69b;
}
.service-tools {
    display: flex;
    align-items: end;
    flex-direction: column;
    gap: 10px;
}
.service-search {
    width: min(310px, 100%);
    min-height: 40px;
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 0 11px;
    border: 1px solid #dfe9df;
    border-radius: 10px;
    background: #fff;
    color: #83a18d;
    box-shadow: 0 4px 16px rgba(29, 67, 49, 0.025);
    transition:
        border-color 0.15s,
        box-shadow 0.15s;
}
.service-search:focus-within {
    border-color: #61a77c;
    box-shadow: 0 0 0 3px #e5f2e9;
}
.service-search input {
    min-width: 0;
    flex: 1;
    border: 0;
    outline: 0;
    background: transparent;
    color: var(--ink);
    font:
        12px 'Noto Sans SC',
        sans-serif;
}
.service-search input::placeholder {
    color: #a8b6ab;
}
.service-search input::-webkit-search-cancel-button {
    display: none;
}
.service-search button {
    display: grid;
    place-items: center;
    padding: 3px;
    border: 0;
    background: transparent;
    color: #7f9b87;
    cursor: pointer;
}
.service-search button:hover {
    color: #246a49;
}
.search-count {
    margin: -2px 0 13px;
    color: #8fa396;
    font:
        11px 'DM Mono',
        monospace;
}
.services-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.service-card {
    min-width: 0;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 13px;
    padding: 18px 19px 16px;
    box-shadow: 0 5px 20px rgba(29, 67, 49, 0.022);
}
.service-top {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 12px;
}
.service-identity {
    display: flex;
    gap: 11px;
    min-width: 0;
}
.service-name-wrap {
    min-width: 0;
}
.service-index {
    font:
        11px 'DM Mono',
        monospace;
    color: #b0c5b7;
    padding-top: 7px;
}
.service-title-line {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
}
.status-indicator {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #aeb9b0;
}
.status-indicator.up {
    background: #2daa6d;
    box-shadow: 0 0 0 4px #e9f6ed;
}
.status-indicator.down {
    background: #df7651;
    box-shadow: 0 0 0 4px #fff0e8;
}
.service-title-line h3 {
    margin: 0;
    font-size: 16px;
    letter-spacing: -0.035em;
}
.state-tag {
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 5px;
    background: #eef1ee;
    color: #7c8a7e;
    margin-left: 3px;
}
.state-tag.up {
    background: #e7f6eb;
    color: #288055;
}
.state-tag.down {
    background: #fff0e8;
    color: #b65738;
}
.service-name-wrap p {
    margin: 5px 0 0;
    color: #98a59b;
    font-size: 12px;
}
.service-rate {
    text-align: right;
    flex: none;
}
.service-rate strong {
    font:
        500 18px 'DM Mono',
        monospace;
    color: #2f7559;
}
.service-rate span {
    display: block;
    color: #a0aa9f;
    font-size: 10px;
    margin-top: 5px;
}
.service-bar {
    display: flex;
    gap: 2px;
    margin-top: 19px;
    height: 19px;
}
.bar-day {
    flex: 1;
    min-width: 2px;
    border-radius: 3px;
    background: #e7ece6;
    transition: transform 0.15s;
}
.bar-day:hover {
    transform: scaleY(1.25);
}
.bar-day.up {
    background: #49b77b;
}
.bar-day.degraded {
    background: #e7b451;
}
.bar-day.down {
    background: #dc7254;
}
.bar-labels {
    display: flex;
    justify-content: space-between;
    font:
        10px 'DM Mono',
        monospace;
    color: #b0bbb1;
    margin-top: 6px;
}
.service-footer {
    border-top: 1px solid #edf1ec;
    margin-top: 13px;
    padding-top: 12px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: start;
    color: #a1aba2;
    font:
        11px 'DM Mono',
        monospace;
}
.service-footer a {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    color: #387559;
    text-decoration: none;
    overflow-wrap: anywhere;
}
.service-footer > span {
    flex: none;
    text-align: right;
}
.service-footer a:hover {
    text-decoration: underline;
}
.service-error {
    color: #b35a3e;
    font-size: 11px;
    margin-top: 9px;
    overflow-wrap: anywhere;
}
.search-empty {
    display: flex;
    align-items: center;
    flex-direction: column;
    gap: 7px;
    padding: 39px 20px;
    border: 1px dashed #d5e2d6;
    border-radius: 13px;
    background: #fff;
    color: #87a08e;
    text-align: center;
    font-size: 12px;
}
.search-empty strong {
    margin-top: 3px;
    color: var(--ink);
    font-size: 15px;
}
.search-empty button {
    margin-top: 9px;
    padding: 7px 13px;
    border: 1px solid #cce2d1;
    border-radius: 7px;
    background: #f1f8f2;
    color: #327852;
    cursor: pointer;
}
.legend {
    display: flex;
    justify-content: flex-end;
    gap: 18px;
    margin: 16px 3px 60px;
    color: #97a69b;
    font-size: 11px;
}
.legend span {
    display: flex;
    align-items: center;
    gap: 6px;
}
.legend i {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 2px;
}
.legend-up {
    background: #49b77b;
}
.legend-degraded {
    background: #e7b451;
}
.legend-down {
    background: #dc7254;
}
.legend-unknown {
    background: #e7ece6;
}
.lower-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
    margin-bottom: 70px;
}
.section-heading.compact {
    min-height: 53px;
    margin-bottom: 16px;
}
.section-heading.compact h2 {
    font-size: 23px;
}
.heading-icon {
    color: #8da99a;
}
.calendar-card,
.events-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 15px;
    min-height: 423px;
    padding: 22px 26px;
}
.calendar-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-bottom: 19px;
}
.calendar-head strong {
    font-size: 14px;
}
.calendar-head button {
    width: 29px;
    height: 29px;
    display: grid;
    place-items: center;
    border: 1px solid #e8eee8;
    border-radius: 7px;
    background: #fff;
    color: #557366;
    cursor: pointer;
}
.calendar-head button:hover:not(:disabled) {
    background: #edf5ef;
}
.calendar-head button:disabled {
    opacity: 0.35;
    cursor: default;
}
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    text-align: center;
}
.weekdays {
    font-size: 11px;
    color: #a3b1a4;
    border-bottom: 1px solid #edf0eb;
    padding: 0 0 13px;
}
.dates {
    row-gap: 6px;
    padding: 14px 0;
}
.dates button {
    border: 0;
    background: none;
    height: 38px;
    display: grid;
    place-items: center;
    font:
        12px 'DM Mono',
        monospace;
    color: #4b6153;
    cursor: pointer;
}
.dates button span {
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    position: relative;
}
.dates button.outside {
    color: #d4dbd4;
}
.dates button:disabled {
    cursor: default;
}
.dates button.down span {
    background: #fae7de;
    color: #c65c3c;
}
.dates button.degraded span {
    background: #fbf1d9;
    color: #a77518;
}
.dates button.up span:after {
    content: '';
    position: absolute;
    bottom: 0;
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: #4fbf81;
}
.dates button.selected span {
    outline: 2px solid #397e5b;
    outline-offset: 2px;
}
.calendar-detail {
    border-top: 1px solid #edf0eb;
    padding-top: 16px;
    min-height: 52px;
    display: flex;
    gap: 12px;
    align-items: center;
    color: #99a79b;
    font-size: 11px;
}
.calendar-detail strong {
    color: #3d7557;
    font:
        11px 'DM Mono',
        monospace;
    white-space: nowrap;
}
.events-card {
    padding: 8px 25px;
}
.event-row {
    display: flex;
    gap: 14px;
    padding: 21px 0;
    border-bottom: 1px solid #edf0eb;
    align-items: start;
    color: inherit;
    text-decoration: none;
    border-radius: 8px;
    transition:
        background-color 0.15s,
        padding 0.15s;
}
.event-row:hover,
.event-row:focus-visible {
    background: #f4f8f3;
    padding-left: 8px;
    padding-right: 8px;
    outline: none;
}
.event-row:focus-visible {
    box-shadow: 0 0 0 2px #75ac83;
}
.event-row:last-child {
    border-bottom: 0;
}
.event-mark {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #e7a06d;
    margin-top: 5px;
    flex: none;
}
.event-row > div {
    display: flex;
    flex-direction: column;
    gap: 6px;
    flex: 1;
}
.event-row strong {
    font-size: 13px;
}
.event-row div span {
    font-size: 11px;
    color: #9daa9e;
}
.event-row time {
    font:
        10px 'DM Mono',
        monospace;
    color: #a7b3a7;
    white-space: nowrap;
}
.empty-events {
    height: 405px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}
.empty-icon {
    width: 55px;
    height: 55px;
    background: #eaf5ec;
    border-radius: 18px;
    color: #47a571;
    display: grid;
    place-items: center;
    margin-bottom: 19px;
}
.empty-events strong {
    font-size: 16px;
}
.empty-events p {
    font-size: 12px;
    color: #91a092;
    margin: 9px 0 24px;
}
.empty-events > span {
    font-size: 10px;
    color: #b4bdb3;
}
.site-footer {
    border-top: 1px solid var(--line);
    padding: 27px 0 38px;
    display: flex;
    justify-content: space-between;
    gap: 18px;
    align-items: center;
    color: #9ca99c;
    font-size: 11px;
}
.footer-brand {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #457b5b;
    font-weight: 700;
}
@media (max-width: 900px) {
    .services-list {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 760px) {
    .site-shell {
        padding: 0 18px;
    }
    .site-header {
        height: 82px;
    }
    .brand-subtitle,
    .header-live {
        display: none;
    }
    .brand-title {
        font-size: 16px;
    }
    .intro-row {
        padding: 48px 0 28px;
    }
    .intro-side {
        display: none;
    }
    .overview {
        padding: 22px;
        align-items: flex-start;
    }
    .overview-text h2 {
        font-size: 19px;
    }
    .overview-stamp {
        display: none;
    }
    .metric-row {
        gap: 8px;
        margin-bottom: 52px;
    }
    .metric {
        padding: 16px;
        min-height: 122px;
    }
    .metric strong {
        font-size: 25px;
    }
    .metric-label,
    .metric-foot {
        font-size: 10px;
    }
    .services-section > .section-heading {
        align-items: stretch;
        flex-direction: column;
        gap: 13px;
    }
    .service-tools {
        align-items: stretch;
    }
    .service-search {
        width: 100%;
        box-sizing: border-box;
    }
    .service-card {
        padding: 17px 18px 15px;
    }
    .service-bar {
        gap: 2px;
        height: 19px;
    }
    .service-rate strong {
        font-size: 17px;
    }
    .service-title-line h3 {
        font-size: 16px;
    }
    .service-footer {
        font-size: 9px;
    }
    .lower-grid {
        grid-template-columns: 1fr;
    }
    .site-footer {
        flex-direction: column;
        align-items: flex-start;
    }
    .section-heading h2 {
        font-size: 23px;
    }
}
@media (max-width: 480px) {
    .refresh-button {
        padding: 9px;
        font-size: 0;
    }
    .refresh-button svg {
        width: 16px;
    }
    .intro-row h1 {
        font-size: 39px;
    }
    .overview-icon {
        width: 40px;
        height: 40px;
    }
    .metric-row {
        grid-template-columns: repeat(2, 1fr);
    }
    .metric:last-child {
        grid-column: span 2;
    }
    .service-top {
        flex-direction: column;
    }
    .service-rate {
        text-align: left;
    }
    .service-identity {
        gap: 10px;
    }
    .service-footer {
        flex-direction: column;
        align-items: flex-start;
    }
    .legend {
        flex-wrap: wrap;
        justify-content: flex-start;
        gap: 9px 15px;
    }
    .calendar-card,
    .events-card {
        padding-left: 17px;
        padding-right: 17px;
    }
}
.overview-pending {
    background: #fff7e7;
    border-color: #f1e1b9;
}
.overview-pending .overview-icon {
    background: #c99737;
}
.status-indicator.pending {
    background: #e5ad4d;
    box-shadow: 0 0 0 4px #fff6e6;
}
.status-indicator.unknown {
    background: #9aa8a0;
    box-shadow: 0 0 0 4px #eef1ee;
}
.state-tag.pending {
    background: #fff3db;
    color: #966c21;
}
.state-tag.unknown {
    background: #eef1ee;
    color: #627168;
}
.overview-maintenance {
    background: #f4f2e8;
    border-color: #e6dfc7;
}
.overview-maintenance .overview-icon {
    background: #9c8550;
}
.maintenance-section {
    margin: 24px 0 0;
    padding: 25px 28px;
    background: #fffdf8;
    border: 1px solid #e9e3d3;
    border-radius: 16px;
}
.maintenance-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #8f7947;
    margin-bottom: 17px;
}
.maintenance-heading span {
    font:
        10px 'DM Mono',
        monospace;
    letter-spacing: 0.14em;
}
.maintenance-heading h2 {
    margin: 4px 0 0;
    font-size: 19px;
    color: #3a483b;
}
.maintenance-notice {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: start;
    gap: 17px;
    padding: 16px 0;
    border-top: 1px solid #eee9db;
}
.maintenance-notice h3 {
    margin: 0 0 6px;
    font-size: 15px;
}
.maintenance-notice p {
    margin: 0;
    color: #728477;
    font-size: 12px;
    line-height: 1.65;
    white-space: pre-wrap;
}
.maintenance-notice .maintenance-meta {
    margin-top: 7px;
    color: #8a7649;
}
.maintenance-notice time {
    font:
        11px/1.6 'DM Mono',
        'Noto Sans SC',
        sans-serif;
    color: #786b4c;
    text-align: right;
    white-space: nowrap;
}
.maintenance-status {
    font-size: 10px;
    font-weight: 700;
    color: #8d743c;
    background: #f5eccc;
    border-radius: 5px;
    padding: 5px 8px;
    white-space: nowrap;
}
.status-indicator.maintenance {
    background: #c5a35e;
    box-shadow: 0 0 0 4px #f8f1df;
}
.state-tag.maintenance,
.maintenance-inline {
    background: #f5ecd1;
    color: #8b6d2f;
}
.maintenance-inline {
    font-size: 10px;
    border-radius: 4px;
    padding: 4px 7px;
    white-space: nowrap;
}
.bar-day.maintenance {
    background: repeating-linear-gradient(
        135deg,
        #cfb675 0 5px,
        #e7dcb5 5px 9px
    );
}
.bar-day.has-maintenance:not(.maintenance) {
    position: relative;
}
.bar-day.has-maintenance:not(.maintenance)::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: #cba963;
}
.legend-maintenance {
    background: repeating-linear-gradient(
        135deg,
        #cfb675 0 5px,
        #e7dcb5 5px 9px
    ) !important;
}
.calendar-grid.dates button.has-maintenance {
    box-shadow: inset 0 -3px #cba963;
}
.maintenance-history {
    margin-top: 30px;
    padding: 24px 28px;
    background: white;
    border: 1px solid #e5eae5;
    border-radius: 16px;
}
.maintenance-history h2 {
    font-size: 18px;
    margin: 0 0 14px;
}
.maintenance-history > div {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    border-top: 1px solid #edf0e8;
    padding: 12px 0;
    color: #7e8d82;
    font-size: 11px;
}
.maintenance-history strong {
    color: #3e5849;
    font-size: 12px;
}
@media (max-width: 700px) {
    .maintenance-notice {
        grid-template-columns: 1fr;
        gap: 8px;
    }
    .maintenance-notice time {
        text-align: left;
        white-space: normal;
    }
    .maintenance-section {
        padding: 19px;
    }
    .maintenance-history > div {
        flex-direction: column;
        gap: 3px;
    }
}
.admin-link {
    color: #276d4c;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.admin-link:hover {
    text-decoration: underline;
}
</style>
