<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    ArrowUpRight,
    Check,
    Clock3,
    Link2Off,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';

type Incident = {
    key: string;
    service_name: string;
    service_url: string;
    started_at: string;
    confirmed_at: string;
    recovered_at: string | null;
    closed_at: string | null;
    status: 'ongoing' | 'recovered' | 'closed';
    duration_seconds: number;
};
const props = defineProps<{ incident: Incident }>();
const openedAt = Date.now();
const now = ref(openedAt);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (props.incident.status === 'ongoing')
        timer = setInterval(() => {
            now.value = Date.now();
        }, 60000);
});
onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const durationSeconds = computed(
    () =>
        props.incident.duration_seconds +
        (props.incident.status === 'ongoing'
            ? Math.max(0, Math.floor((now.value - openedAt) / 1000))
            : 0),
);
const duration = computed(() => {
    const minutes = Math.floor(durationSeconds.value / 60);
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    const rest = minutes % 60;
    return [
        days ? `${days} 天` : '',
        hours ? `${hours} 小时` : '',
        `${rest} 分钟`,
    ]
        .filter(Boolean)
        .join(' ');
});
const statusText = computed(
    () =>
        ({
            ongoing: '故障持续中',
            recovered: '服务已恢复',
            closed: '监测已终止',
        })[props.incident.status],
);
const dateTime = (value: string) =>
    new Intl.DateTimeFormat('zh-CN', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).format(new Date(value));
</script>

<template>
    <Head :title="`${incident.service_name} · 异常详情`" />
    <div class="detail-page" :class="`detail-${incident.status}`">
        <div class="top-line"></div>
        <div class="shell">
            <header class="site-header">
                <a class="brand" href="/"
                    ><span class="brand-icon"><Activity :size="22" /></span
                    ><span
                        >校园服务状态<small>CAMPUS SERVICE STATUS</small></span
                    ></a
                ><a class="header-return" href="/"
                    ><ArrowLeft :size="16" />返回状态页</a
                >
            </header>
            <main>
                <a class="breadcrumb" href="/"
                    ><ArrowLeft :size="15" />异常记录 <span>/</span> 详情</a
                >
                <section class="hero" aria-label="故障状态">
                    <div class="hero-copy">
                        <span class="eyebrow"
                            >INCIDENT REPORT / {{ incident.key }}</span
                        >
                        <div class="hero-title">
                            <div class="hero-icon">
                                <Check
                                    v-if="incident.status === 'recovered'"
                                    :size="27"
                                /><Link2Off
                                    v-else-if="incident.status === 'closed'"
                                    :size="27"
                                /><Activity v-else :size="27" />
                            </div>
                            <div>
                                <span class="status-label">{{
                                    statusText
                                }}</span>
                                <h1>{{ incident.service_name }}</h1>
                            </div>
                        </div>
                        <p>
                            {{
                                incident.status === 'ongoing'
                                    ? '服务已连续两次探测失败，故障仍在持续。'
                                    : incident.status === 'recovered'
                                      ? '定时探测确认服务已恢复访问。'
                                      : '该监测周期已结束；未记录到服务恢复探测。'
                            }}
                        </p>
                    </div>
                    <div class="duration">
                        <span>异常持续时长</span><strong>{{ duration }}</strong
                        ><small>{{
                            incident.status === 'ongoing'
                                ? '持续更新 · 北京时间'
                                : '首次失败至事件结束'
                        }}</small>
                    </div>
                </section>
                <div class="content-grid">
                    <section class="timeline-card" aria-label="事件时间线">
                        <div class="section-heading">
                            <span class="section-number">01</span>
                            <div>
                                <span class="eyebrow">EVENT TIMELINE</span>
                                <h2>事件时间线</h2>
                            </div>
                        </div>
                        <ol class="timeline">
                            <li>
                                <span class="point first"></span>
                                <div>
                                    <small>首次异常 · 检测开始</small>
                                    <h3>{{ dateTime(incident.started_at) }}</h3>
                                    <p>首次定时探测失败，开始记录可能异常。</p>
                                </div>
                            </li>
                            <li>
                                <span class="point confirmed"></span>
                                <div>
                                    <small>故障确认</small>
                                    <h3>
                                        {{ dateTime(incident.confirmed_at) }}
                                    </h3>
                                    <p>
                                        连续两次定时探测失败，确认服务访问异常。
                                    </p>
                                </div>
                            </li>
                            <li :class="incident.status">
                                <span class="point last"></span>
                                <div>
                                    <small>{{
                                        incident.status === 'recovered'
                                            ? '恢复时间'
                                            : incident.status === 'closed'
                                              ? '监测终止时间'
                                              : '恢复时间'
                                    }}</small>
                                    <h3>
                                        {{
                                            incident.recovered_at
                                                ? dateTime(
                                                      incident.recovered_at,
                                                  )
                                                : incident.closed_at
                                                  ? dateTime(incident.closed_at)
                                                  : '尚未恢复'
                                        }}
                                    </h3>
                                    <p>
                                        {{
                                            incident.status === 'recovered'
                                                ? '后续定时探测成功，确认服务恢复。'
                                                : incident.status === 'closed'
                                                  ? '监测项已停用或更换地址，不能将此时间视为恢复。'
                                                  : '持续监测中，恢复后会在此显示准确时间。'
                                        }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </section>
                    <aside class="service-card">
                        <div class="section-heading">
                            <span class="section-number">02</span>
                            <div>
                                <span class="eyebrow">AFFECTED SERVICE</span>
                                <h2>受影响服务</h2>
                            </div>
                        </div>
                        <div class="service-details">
                            <span>服务名称</span
                            ><strong>{{ incident.service_name }}</strong
                            ><span>监测地址</span
                            ><a
                                :href="incident.service_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ incident.service_url }}
                                <ArrowUpRight :size="14" /></a
                            ><span>事件编号</span
                            ><code>{{ incident.key }}</code>
                        </div>
                        <div class="timezone-note">
                            <Clock3 :size="16" /><span
                                >页面时间均按北京时间（UTC+8）显示。数据反映本站校内探测节点的访问结果。</span
                            >
                        </div>
                    </aside>
                </div>
                <a class="bottom-return" href="/"
                    ><ArrowLeft :size="16" />返回全部服务状态</a
                >
            </main>
            <footer>
                <span>校园服务状态 · 故障详情</span
                ><span>每 2 分钟探测一次</span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;600;700;800&display=swap');
.detail-page {
    --ink: #19352b;
    --line: #e5eae5;
    min-height: 100vh;
    background: #f8faf6;
    color: var(--ink);
    font:
        14px 'Noto Sans SC',
        sans-serif;
}
.top-line {
    height: 5px;
    background: linear-gradient(90deg, #143e32, #438d67 70%, #d7e7b6);
}
.shell {
    max-width: 1120px;
    margin: auto;
    padding: 0 32px;
}
.site-header {
    min-height: 96px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    border-bottom: 1px solid var(--line);
}
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    color: var(--ink);
    font-size: 17px;
    font-weight: 800;
    text-decoration: none;
}
.brand-icon {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #193e34;
    color: white;
}
.brand small {
    display: block;
    margin-top: 2px;
    font:
        10px 'DM Mono',
        monospace;
    letter-spacing: 0.16em;
    color: #9aaa9e;
}
.header-return,
.breadcrumb,
.bottom-return {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #688b74;
    text-decoration: none;
    font-size: 12px;
}
.header-return:hover,
.breadcrumb:hover,
.bottom-return:hover {
    text-decoration: underline;
}
.breadcrumb {
    margin: 42px 0 22px;
}
.breadcrumb span {
    color: #acbaae;
}
.hero {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 30px;
    padding: 36px 42px;
    background: #fff1e9;
    border: 1px solid #f1ded0;
    border-radius: 18px;
    position: relative;
    overflow: hidden;
}
.hero:after {
    content: '';
    position: absolute;
    width: 290px;
    height: 290px;
    border: 1px solid rgba(166, 86, 48, 0.12);
    border-radius: 50%;
    right: 155px;
    top: -225px;
    pointer-events: none;
}
.eyebrow {
    font:
        10px 'DM Mono',
        monospace;
    letter-spacing: 0.14em;
    color: #8c986f;
}
.hero .eyebrow {
    color: #a87052;
}
.hero-title {
    display: flex;
    align-items: center;
    gap: 17px;
    margin-top: 20px;
}
.hero-icon {
    width: 54px;
    height: 54px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    background: #b96042;
    color: white;
    flex: none;
}
.status-label {
    font-size: 11px;
    font-weight: 700;
    color: #ad6547;
}
.hero h1 {
    font-size: clamp(28px, 3.5vw, 42px);
    letter-spacing: -0.055em;
    margin: 3px 0 0;
}
.hero p {
    margin: 18px 0 0;
    color: #8d776a;
    font-size: 12px;
}
.duration {
    text-align: right;
    position: relative;
    z-index: 1;
}
.duration span,
.duration small {
    display: block;
    color: #9b8374;
    font-size: 11px;
}
.duration strong {
    display: block;
    margin: 8px 0;
    font:
        500 clamp(24px, 2.6vw, 33px) 'DM Mono',
        'Noto Sans SC',
        sans-serif;
    white-space: nowrap;
    color: #a75236;
}
.detail-recovered .hero {
    background: #eaf5ec;
    border-color: #d9e9dc;
}
.detail-recovered .hero-icon {
    background: #328b5b;
}
.detail-recovered .status-label,
.detail-recovered .duration strong {
    color: #2d7b52;
}
.detail-closed .hero {
    background: #eff1ee;
    border-color: #e1e5df;
}
.detail-closed .hero-icon {
    background: #78887d;
}
.detail-closed .status-label,
.detail-closed .duration strong {
    color: #63776a;
}
.content-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.8fr);
    gap: 22px;
    margin-top: 22px;
}
.timeline-card,
.service-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 30px 32px;
    box-shadow: 0 9px 28px rgba(21, 65, 39, 0.035);
}
.section-heading {
    display: flex;
    align-items: center;
    gap: 13px;
}
.section-number {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #e8f3e9;
    color: #4a9065;
    font:
        12px 'DM Mono',
        monospace;
}
.section-heading h2 {
    font-size: 20px;
    margin: 5px 0 0;
    letter-spacing: -0.04em;
}
.timeline {
    list-style: none;
    padding: 0;
    margin: 33px 0 0 10px;
}
.timeline li {
    display: grid;
    grid-template-columns: 24px 1fr;
    gap: 14px;
    position: relative;
    min-height: 110px;
}
.timeline li:not(:last-child):before {
    content: '';
    position: absolute;
    left: 8px;
    top: 12px;
    bottom: -2px;
    width: 1px;
    background: #dbe7dc;
}
.point {
    width: 17px;
    height: 17px;
    margin-top: 2px;
    background: #e9f4e9;
    border: 4px solid #4d9b68;
    box-sizing: border-box;
    border-radius: 50%;
    position: relative;
    z-index: 1;
}
.point.first {
    border-color: #d9a064;
    background: #fff5e8;
}
.point.confirmed {
    border-color: #bb7252;
    background: #fff0e8;
}
.point.last {
    border-color: #a0aea4;
    background: #f0f3ef;
}
.recovered .point.last {
    border-color: #3e9a65;
}
.timeline small {
    font-size: 10px;
    font-weight: 700;
    color: #8b9b8d;
}
.timeline h3 {
    font:
        500 18px 'DM Mono',
        'Noto Sans SC',
        sans-serif;
    color: #294b37;
    margin: 6px 0;
}
.timeline p {
    font-size: 11px;
    color: #99a89b;
    margin: 0;
    line-height: 1.6;
}
.service-details {
    display: grid;
    gap: 8px;
    margin-top: 32px;
}
.service-details > span {
    font-size: 10px;
    color: #9ba99c;
    margin-top: 12px;
}
.service-details strong {
    font-size: 16px;
}
.service-details a {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
    overflow-wrap: anywhere;
    color: #34805a;
    font:
        12px 'DM Mono',
        monospace;
    text-decoration: none;
}
.service-details a:hover {
    text-decoration: underline;
}
.service-details code {
    font:
        11px 'DM Mono',
        monospace;
    color: #738e79;
    overflow-wrap: anywhere;
}
.timezone-note {
    display: flex;
    gap: 10px;
    margin-top: 29px;
    padding: 13px;
    background: #f2f7f1;
    border-radius: 9px;
    color: #7b9180;
    font-size: 11px;
    line-height: 1.6;
}
.timezone-note svg {
    flex: none;
    margin-top: 1px;
}
.bottom-return {
    margin: 29px 0 56px;
}
footer {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    border-top: 1px solid var(--line);
    padding: 24px 0 35px;
    color: #9daea1;
    font-size: 11px;
}
@media (max-width: 760px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
    .hero {
        align-items: start;
        flex-direction: column;
    }
    .duration {
        text-align: left;
    }
    .timeline-card,
    .service-card {
        padding: 25px;
    }
}
@media (max-width: 480px) {
    .shell {
        padding: 0 17px;
    }
    .site-header {
        min-height: 78px;
    }
    .brand {
        font-size: 14px;
    }
    .brand small {
        font-size: 8px;
    }
    .header-return {
        font-size: 0;
    }
    .header-return svg {
        width: 20px;
        height: 20px;
    }
    .breadcrumb {
        margin-top: 29px;
    }
    .hero {
        padding: 26px 22px;
    }
    .hero-title {
        align-items: start;
    }
    .hero-icon {
        width: 45px;
        height: 45px;
    }
    .hero-icon svg {
        width: 23px;
    }
    .duration strong {
        font-size: 25px;
    }
    .timeline-card,
    .service-card {
        padding: 21px;
    }
    .timeline h3 {
        font-size: 15px;
    }
    footer {
        flex-direction: column;
        gap: 5px;
    }
}
</style>
