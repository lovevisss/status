<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    CalendarClock,
    Check,
    Clock3,
    Plus,
    Save,
    X,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';

type Service = { id: number; name: string; url: string };
type Window = {
    id: number;
    title: string;
    description: string;
    starts_at: string;
    ends_at: string;
    effective_end_at: string;
    cancelled_at: string | null;
    ended_at: string | null;
    service_ids: number[];
    service_names: string[];
    needs_attention: boolean;
};
const props = defineProps<{
    services: Service[];
    windows: Window[];
    message: string | null;
}>();
const editingId = ref<number | null>(null);
const activeEdit = ref(false);
const form = useForm({
    title: '',
    description: '',
    starts_at: '',
    ends_at: '',
    services: [] as number[],
});
const now = ref(Date.now());
let refreshTimer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    refreshTimer = setInterval(() => {
        now.value = Date.now();
        router.reload({ only: ['windows', 'services'] });
    }, 120000);
});
onUnmounted(() => {
    if (refreshTimer) clearInterval(refreshTimer);
});
const upcoming = computed(
    () =>
        props.windows.filter(
            (window) =>
                !window.cancelled_at &&
                new Date(window.effective_end_at).getTime() > now.value,
        ).length,
);
const localInput = (value: string) => {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date(value));
    const part = (type: string) =>
        parts.find((entry) => entry.type === type)?.value ?? '';
    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
};
const label = (value: string) =>
    new Intl.DateTimeFormat('zh-CN', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
const state = (window: Window) =>
    window.cancelled_at
        ? '已取消'
        : window.ended_at || new Date(window.ends_at).getTime() <= now.value
          ? '已结束'
          : new Date(window.starts_at).getTime() <= now.value
            ? '维护中'
            : '待开始';
function resetForm() {
    editingId.value = null;
    activeEdit.value = false;
    form.reset();
    form.clearErrors();
}
function edit(window: Window) {
    editingId.value = window.id;
    activeEdit.value = state(window) === '维护中';
    form.title = window.title;
    form.description = window.description;
    form.starts_at = localInput(window.starts_at);
    form.ends_at = localInput(window.ends_at);
    form.services = [...window.service_ids];
    form.clearErrors();
    windowScroll();
}
function windowScroll() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function submit() {
    const options = { preserveScroll: true, onSuccess: resetForm };
    if (editingId.value === null) form.post('/admin/maintenance', options);
    else form.put(`/admin/maintenance/${editingId.value}`, options);
}
function action(window: Window, kind: 'cancel' | 'finish') {
    if (
        !confirm(
            kind === 'cancel'
                ? `取消「${window.title}」？`
                : `现在结束「${window.title}」？`,
        )
    )
        return;
    router.post(
        `/admin/maintenance/${window.id}/${kind}`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="计划维护管理" />
    <div class="page">
        <div class="top-line"></div>
        <div class="shell">
            <header class="header">
                <div class="brand">
                    <span class="brand-icon"><Activity :size="22" /></span
                    ><span>校园服务状态<small>MONITOR CONTROL</small></span>
                </div>
                <nav aria-label="管理导航">
                    <a href="/admin/monitors">监测服务</a
                    ><a href="/admin/recipients">邮件收件人</a
                    ><a
                        class="current"
                        href="/admin/maintenance"
                        aria-current="page"
                        >计划维护</a
                    ><a href="/"><ArrowLeft :size="15" /> 公开状态页</a>
                </nav>
            </header>
            <main>
                <div class="heading">
                    <div>
                        <span class="eyebrow">ADMIN CONSOLE / 03</span>
                        <h1>计划维护<span>.</span></h1>
                        <p>
                            提前说明服务维护时间，让师生知道何时可能受到影响。
                        </p>
                    </div>
                    <div class="counter">
                        <strong>{{
                            upcoming.toString().padStart(2, '0')
                        }}</strong
                        ><span>项待开始或进行中</span>
                    </div>
                </div>
                <div v-if="message" class="notice" role="status">
                    <Check :size="17" />{{ message }}
                </div>
                <div class="layout">
                    <section class="form-card" aria-label="维护计划表单">
                        <div class="card-heading">
                            <span class="card-number">01</span>
                            <div>
                                <span class="eyebrow">MAINTENANCE WINDOW</span>
                                <h2>
                                    {{
                                        editingId === null
                                            ? '安排维护'
                                            : activeEdit
                                              ? '调整结束时间'
                                              : '编辑维护计划'
                                    }}
                                </h2>
                            </div>
                            <CalendarClock :size="22" />
                        </div>
                        <form @submit.prevent="submit">
                            <label for="maintenance-title">维护标题</label
                            ><input
                                id="maintenance-title"
                                v-model="form.title"
                                maxlength="120"
                                :disabled="activeEdit"
                                required
                                placeholder="例如：统一身份认证系统升级"
                            />
                            <p v-if="form.errors.title" class="error">
                                {{ form.errors.title }}
                            </p>
                            <label for="maintenance-description">简要说明</label
                            ><textarea
                                id="maintenance-description"
                                v-model="form.description"
                                maxlength="2000"
                                rows="3"
                                :disabled="activeEdit"
                                required
                                placeholder="说明维护内容和预计影响"
                            />
                            <p v-if="form.errors.description" class="error">
                                {{ form.errors.description }}
                            </p>
                            <label>受影响服务 <span>可多选</span></label>
                            <div class="service-options">
                                <label
                                    v-for="service in services"
                                    :key="service.id"
                                    class="service-option"
                                    ><input
                                        v-model="form.services"
                                        type="checkbox"
                                        :value="service.id"
                                        :disabled="activeEdit"
                                    /><span
                                        ><strong>{{ service.name }}</strong
                                        ><small>{{ service.url }}</small></span
                                    ></label
                                >
                            </div>
                            <p v-if="form.errors.services" class="error">
                                {{ form.errors.services }}
                            </p>
                            <div class="date-grid">
                                <div>
                                    <label for="maintenance-start"
                                        >开始 · 北京时间</label
                                    ><input
                                        id="maintenance-start"
                                        v-model="form.starts_at"
                                        type="datetime-local"
                                        :disabled="activeEdit"
                                        required
                                    />
                                    <p
                                        v-if="form.errors.starts_at"
                                        class="error"
                                    >
                                        {{ form.errors.starts_at }}
                                    </p>
                                </div>
                                <div>
                                    <label for="maintenance-end"
                                        >结束 · 北京时间</label
                                    ><input
                                        id="maintenance-end"
                                        v-model="form.ends_at"
                                        type="datetime-local"
                                        required
                                    />
                                    <p v-if="form.errors.ends_at" class="error">
                                        {{ form.errors.ends_at }}
                                    </p>
                                </div>
                            </div>
                            <p class="hint">
                                维护开始前 7
                                天公开预告。期间继续探测，维护样本不计入可用率。
                            </p>
                            <div class="form-actions">
                                <button
                                    type="submit"
                                    class="primary"
                                    :disabled="form.processing"
                                >
                                    <Plus
                                        v-if="editingId === null"
                                        :size="16"
                                    /><Save v-else :size="16" />{{
                                        form.processing ? '保存中…' : '保存计划'
                                    }}</button
                                ><button
                                    v-if="editingId !== null"
                                    type="button"
                                    class="quiet"
                                    @click="resetForm"
                                >
                                    <X :size="15" />取消编辑
                                </button>
                            </div>
                        </form>
                    </section>
                    <section class="list" aria-label="维护计划列表">
                        <div class="list-heading">
                            <div>
                                <span class="eyebrow"
                                    >SCHEDULE /
                                    {{
                                        windows.length
                                            .toString()
                                            .padStart(2, '0')
                                    }}</span
                                >
                                <h2>维护安排</h2>
                            </div>
                            <span>时间均为北京时间</span>
                        </div>
                        <div v-if="windows.length === 0" class="empty">
                            <Clock3 :size="26" /><strong>尚无维护计划</strong
                            ><span>添加计划后，这里会展示时间表。</span>
                        </div>
                        <article
                            v-for="window in windows"
                            :key="window.id"
                            class="window-card"
                            :class="{
                                closed:
                                    state(window) === '已结束' ||
                                    state(window) === '已取消',
                            }"
                        >
                            <div class="window-head">
                                <span
                                    class="state"
                                    :class="
                                        state(window) === '维护中'
                                            ? 'active'
                                            : ''
                                    "
                                    >{{ state(window) }}</span
                                ><span
                                    v-if="window.needs_attention"
                                    class="attention"
                                    >部分服务已变更，请重新选择</span
                                >
                            </div>
                            <h3>{{ window.title }}</h3>
                            <p>{{ window.description }}</p>
                            <div class="window-time">
                                <CalendarClock :size="17" /><span
                                    >{{ label(window.starts_at) }}<br />{{
                                        label(window.effective_end_at)
                                    }}</span
                                >
                            </div>
                            <div class="tags">
                                <span
                                    v-for="name in window.service_names"
                                    :key="name"
                                    >{{ name }}</span
                                >
                            </div>
                            <div
                                v-if="
                                    state(window) === '待开始' ||
                                    state(window) === '维护中'
                                "
                                class="actions"
                            >
                                <button type="button" @click="edit(window)">
                                    {{
                                        state(window) === '维护中'
                                            ? '调整结束时间'
                                            : '编辑'
                                    }}</button
                                ><button
                                    v-if="state(window) === '待开始'"
                                    type="button"
                                    @click="action(window, 'cancel')"
                                >
                                    取消计划</button
                                ><button
                                    v-else
                                    type="button"
                                    @click="action(window, 'finish')"
                                >
                                    提前结束
                                </button>
                            </div>
                        </article>
                    </section>
                </div>
            </main>
            <footer>
                校园服务状态 · 计划维护管理
                <span>维护预告仅在公开状态页展示</span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;600;700;800&display=swap');
.page {
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
    max-width: 1200px;
    margin: auto;
    padding: 0 32px;
}
.header {
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
    font-size: 17px;
    font-weight: 800;
}
.brand-icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    background: #193e34;
    color: white;
    border-radius: 12px;
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
.header nav {
    display: flex;
    align-items: center;
    gap: 18px;
}
.header nav a {
    display: flex;
    align-items: center;
    gap: 4px;
    color: #718c78;
    font-size: 12px;
    text-decoration: none;
    white-space: nowrap;
}
.header nav a.current {
    color: #286c4b;
    font-weight: 700;
}
.header nav a:hover {
    text-decoration: underline;
}
.heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 24px;
    padding: 62px 0 38px;
}
.eyebrow {
    font:
        10px 'DM Mono',
        monospace;
    letter-spacing: 0.14em;
    color: #6a9a79;
}
.heading h1 {
    font-size: clamp(34px, 4.5vw, 54px);
    letter-spacing: -0.07em;
    margin: 17px 0 10px;
}
.heading h1 span {
    color: #64a87a;
}
.heading p {
    color: #819287;
    font-size: 13px;
    margin: 0;
}
.counter {
    text-align: right;
    border-left: 1px solid #dce6dc;
    padding-left: 25px;
}
.counter strong {
    display: block;
    font:
        500 42px 'DM Mono',
        monospace;
    color: #2f7856;
    line-height: 1;
}
.counter span {
    display: block;
    margin-top: 9px;
    color: #92a096;
    font-size: 11px;
}
.notice {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 13px 16px;
    margin-bottom: 20px;
    background: #e9f5e9;
    color: #287348;
    border: 1px solid #d6ead9;
    border-radius: 10px;
    font-size: 12px;
}
.layout {
    display: grid;
    grid-template-columns: minmax(320px, 410px) minmax(0, 1fr);
    align-items: start;
    gap: 30px;
}
.form-card,
.window-card,
.empty {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 16px;
    box-shadow: 0 9px 28px rgba(21, 65, 39, 0.035);
}
.form-card {
    padding: 26px;
}
.card-heading {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-bottom: 23px;
}
.card-heading > svg {
    margin-left: auto;
    color: #8cad91;
}
.card-heading h2,
.list-heading h2 {
    font-size: 21px;
    letter-spacing: -0.04em;
    margin: 5px 0 0;
}
.card-number {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    background: #e8f3e9;
    border-radius: 10px;
    color: #4a9065;
    font:
        12px 'DM Mono',
        monospace;
}
.form-card label {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    font-weight: 700;
    margin: 18px 0 8px;
}
.form-card label span {
    color: #9fac9f;
    font-size: 10px;
    font-weight: 500;
}
.form-card input:not([type='checkbox']),
.form-card textarea {
    box-sizing: border-box;
    width: 100%;
    background: #fbfcfa;
    border: 1px solid #dce6dc;
    border-radius: 9px;
    padding: 11px 12px;
    color: #244534;
    font:
        12px 'Noto Sans SC',
        sans-serif;
    outline: 0;
}
.form-card input:focus,
.form-card textarea:focus {
    border-color: #4eaa76;
}
.form-card input:disabled,
.form-card textarea:disabled {
    opacity: 0.6;
}
.service-options {
    display: grid;
    gap: 7px;
    max-height: 220px;
    overflow: auto;
}
.form-card .service-option {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    margin: 0;
    padding: 10px 11px;
    background: #f5f8f4;
    border-radius: 9px;
    cursor: pointer;
}
.service-option input {
    accent-color: #317650;
}
.service-option span {
    display: grid;
    min-width: 0;
}
.service-option strong {
    font-size: 12px;
}
.service-option small {
    color: #8fa397;
    font-size: 10px;
    overflow-wrap: anywhere;
}
.date-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.hint {
    font-size: 11px;
    color: #829789;
    line-height: 1.6;
    margin: 18px 0;
}
.error {
    color: #b8583d;
    font-size: 11px;
    margin: 6px 0;
}
.form-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}
.primary {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    background: #225e43;
    color: white;
    border: 0;
    border-radius: 9px;
    padding: 12px 17px;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
}
.primary:disabled {
    opacity: 0.6;
}
.quiet {
    display: flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: 0;
    color: #728c79;
    font-size: 11px;
    cursor: pointer;
}
.list-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.list-heading > span {
    color: #a1aea4;
    font-size: 11px;
}
.window-card {
    padding: 22px 24px;
    margin-bottom: 13px;
}
.window-card.closed {
    opacity: 0.7;
}
.window-head {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.state {
    font-size: 10px;
    font-weight: 700;
    color: #4a7d61;
    background: #edf5ee;
    padding: 5px 8px;
    border-radius: 5px;
}
.state.active {
    background: #e5f4ea;
    color: #26784b;
}
.attention {
    font-size: 10px;
    color: #ad673d;
    background: #fff2e7;
    padding: 5px 8px;
    border-radius: 5px;
}
.window-card h3 {
    font-size: 18px;
    margin: 16px 0 7px;
}
.window-card p {
    font-size: 12px;
    line-height: 1.65;
    color: #809287;
    margin: 0;
    white-space: pre-wrap;
}
.window-time {
    display: flex;
    align-items: start;
    gap: 9px;
    margin-top: 19px;
    color: #377456;
    font:
        12px/1.8 'DM Mono',
        'Noto Sans SC',
        sans-serif;
}
.tags {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    margin-top: 15px;
}
.tags span {
    font-size: 11px;
    color: #678673;
    background: #f0f5ef;
    border-radius: 5px;
    padding: 5px 8px;
}
.actions {
    display: flex;
    gap: 16px;
    border-top: 1px solid #eef2ec;
    margin-top: 19px;
    padding-top: 15px;
}
.actions button {
    background: none;
    border: 0;
    color: #567b62;
    font-size: 11px;
    cursor: pointer;
    padding: 0;
}
.actions button:hover {
    color: #18563a;
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 9px;
    padding: 46px 25px;
    color: #8ba18f;
    font-size: 12px;
}
.empty strong {
    font-size: 16px;
    color: #244936;
}
footer {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    border-top: 1px solid var(--line);
    margin-top: 60px;
    padding: 24px 0 35px;
    color: #9daea1;
    font-size: 11px;
}
@media (max-width: 850px) {
    .layout {
        grid-template-columns: 1fr;
    }
    .heading {
        padding-top: 46px;
    }
    .counter {
        display: none;
    }
}
@media (max-width: 620px) {
    .shell {
        padding: 0 17px;
    }
    .header {
        min-height: 76px;
        flex-wrap: wrap;
        padding: 13px 0;
    }
    .brand {
        font-size: 14px;
    }
    .brand small {
        font-size: 8px;
    }
    .header nav {
        gap: 9px;
        width: 100%;
        justify-content: space-between;
    }
    .header nav a {
        font-size: 10px;
    }
    .header nav a:last-child {
        font-size: 0;
    }
    .header nav a:last-child svg {
        width: 18px;
    }
    .heading h1 {
        font-size: 33px;
    }
    .date-grid {
        grid-template-columns: 1fr;
    }
    .form-card {
        padding: 21px;
    }
    .window-card {
        padding: 19px;
    }
    .list-heading > span {
        display: none;
    }
    footer {
        flex-direction: column;
        gap: 5px;
    }
}
</style>
