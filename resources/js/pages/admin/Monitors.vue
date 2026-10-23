<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    ArrowUpRight,
    Check,
    CirclePause,
    Plus,
    RotateCcw,
    Save,
    ShieldCheck,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';

type Monitor = {
    id: number;
    name: string;
    description: string;
    url: string;
    is_enabled: boolean;
};
type ArchivedMonitor = {
    id: number;
    name: string;
    url: string;
    archived_at: string;
};

const props = defineProps<{
    services: Monitor[];
    archived: ArchivedMonitor[];
    message: string | null;
}>();

const editingId = ref<number | null>(null);
const form = useForm({ name: '', description: '', url: '' });
const enabledCount = computed(
    () => props.services.filter((item) => item.is_enabled).length,
);

function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function edit(item: Monitor) {
    editingId.value = item.id;
    form.name = item.name;
    form.description = item.description;
    form.url = item.url;
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function submit() {
    const options = { preserveScroll: true, onSuccess: resetForm };
    if (editingId.value !== null) {
        form.put(`/admin/monitors/${editingId.value}`, options);
    } else {
        form.post('/admin/monitors', options);
    }
}

function disable(item: Monitor) {
    if (
        !window.confirm(
            `停用「${item.name}」？公开页面将隐藏它，未提交的告警会取消。`,
        )
    )
        return;
    router.post(
        `/admin/monitors/${item.id}/disable`,
        {},
        { preserveScroll: true },
    );
}

function enable(item: Monitor) {
    router.post(
        `/admin/monitors/${item.id}/enable`,
        {},
        { preserveScroll: true },
    );
}

const dateLabel = (value: string) =>
    new Intl.DateTimeFormat('zh-CN', {
        timeZone: 'Asia/Shanghai',
        month: 'long',
        day: 'numeric',
    }).format(new Date(value));
</script>

<template>
    <Head title="监测管理" />
    <div class="admin-page">
        <div class="top-line"></div>
        <div class="shell">
            <header class="site-header">
                <div class="brand">
                    <span class="brand-icon"><Activity :size="22" /></span
                    ><span
                        >校园服务状态<span class="brand-dot">.</span
                        ><small>MONITOR CONTROL</small></span
                    >
                </div>
                <nav class="admin-links" aria-label="管理导航">
                    <a
                        class="current"
                        href="/admin/monitors"
                        aria-current="page"
                        >监测服务</a
                    >
                    <a href="/admin/recipients">邮件收件人</a>
                    <a class="back-link" href="/"
                        ><ArrowLeft :size="15" /> 返回公开状态页</a
                    >
                </nav>
            </header>

            <main>
                <div class="page-heading">
                    <div>
                        <span class="eyebrow"
                            ><span></span> ADMIN CONSOLE / 01</span
                        >
                        <h1>监测服务管理<span>.</span></h1>
                        <p>
                            在这里添加校内网站。保存后，下一次两分钟定时任务会开始探测。
                        </p>
                    </div>
                    <div class="live-count">
                        <strong>{{
                            enabledCount.toString().padStart(2, '0')
                        }}</strong
                        ><span>项服务正在监测</span>
                    </div>
                </div>

                <div v-if="message" class="notice" role="status">
                    <Check :size="17" /> {{ message }}
                </div>

                <div class="content-grid">
                    <section class="form-card" aria-label="添加或编辑监测网址">
                        <div class="card-title">
                            <span class="section-index">01</span>
                            <div>
                                <span class="mini-label">SERVICE DETAILS</span>
                                <h2>
                                    {{
                                        editingId === null
                                            ? '添加监测网址'
                                            : '编辑监测项'
                                    }}
                                </h2>
                            </div>
                            <Plus v-if="editingId === null" :size="20" /><Save
                                v-else
                                :size="20"
                            />
                        </div>
                        <form @submit.prevent="submit">
                            <label for="monitor-name"
                                >服务名称 <span>必填</span></label
                            >
                            <input
                                id="monitor-name"
                                v-model="form.name"
                                maxlength="120"
                                required
                                placeholder="例如：教务系统"
                            />
                            <p v-if="form.errors.name" class="field-error">
                                {{ form.errors.name }}
                            </p>

                            <label for="monitor-url"
                                >完整网址 <span>必填</span></label
                            >
                            <input
                                id="monitor-url"
                                v-model="form.url"
                                maxlength="2048"
                                required
                                type="url"
                                placeholder="https://example.zufedfc.edu.cn/"
                                spellcheck="false"
                            />
                            <p v-if="form.errors.url" class="field-error">
                                {{ form.errors.url }}
                            </p>

                            <label for="monitor-description"
                                >简短说明 <span>选填</span></label
                            >
                            <textarea
                                id="monitor-description"
                                v-model="form.description"
                                maxlength="255"
                                rows="3"
                                placeholder="这项服务供谁使用？"
                            ></textarea>
                            <p
                                v-if="form.errors.description"
                                class="field-error"
                            >
                                {{ form.errors.description }}
                            </p>

                            <div class="form-help">
                                <ShieldCheck :size="16" /><span
                                    >支持学校域名和 10.0.0.0/8
                                    内网地址。网址改变后将从新的监测周期开始计算可用率。</span
                                >
                            </div>
                            <div class="form-actions">
                                <button
                                    class="primary-button"
                                    type="submit"
                                    :disabled="form.processing"
                                >
                                    <Plus
                                        v-if="editingId === null"
                                        :size="17"
                                    /><Save v-else :size="17" />{{
                                        form.processing
                                            ? '保存中…'
                                            : editingId === null
                                              ? '添加服务'
                                              : '保存更改'
                                    }}</button
                                ><button
                                    v-if="editingId !== null"
                                    class="text-button"
                                    type="button"
                                    @click="resetForm"
                                >
                                    <X :size="16" /> 取消编辑
                                </button>
                            </div>
                        </form>
                    </section>

                    <section class="service-section" aria-label="当前监测服务">
                        <div class="list-heading">
                            <div>
                                <span class="mini-label"
                                    >CURRENT SERVICES /
                                    {{
                                        services.length
                                            .toString()
                                            .padStart(2, '0')
                                    }}</span
                                >
                                <h2>当前服务</h2>
                            </div>
                            <span>已停用的服务不会出现在公开页</span>
                        </div>
                        <div v-if="services.length === 0" class="empty-card">
                            还没有监测服务。请使用左侧表单添加。
                        </div>
                        <article
                            v-for="(item, index) in services"
                            :key="item.id"
                            class="service-card"
                            :class="{ disabled: !item.is_enabled }"
                        >
                            <div class="service-card-head">
                                <span class="number">{{
                                    String(index + 1).padStart(2, '0')
                                }}</span
                                ><span
                                    class="service-status"
                                    :class="item.is_enabled ? 'on' : 'off'"
                                    ><span class="status-dot"></span
                                    >{{
                                        item.is_enabled ? '监测中' : '已停用'
                                    }}</span
                                >
                            </div>
                            <h3>{{ item.name }}</h3>
                            <p class="description">
                                {{ item.description || '暂无说明' }}
                            </p>
                            <a
                                class="url"
                                :href="item.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                >{{ item.url }} <ArrowUpRight :size="14"
                            /></a>
                            <div class="card-actions">
                                <button type="button" @click="edit(item)">
                                    编辑</button
                                ><button
                                    v-if="item.is_enabled"
                                    type="button"
                                    @click="disable(item)"
                                >
                                    <CirclePause :size="15" /> 停用</button
                                ><button
                                    v-else
                                    type="button"
                                    @click="enable(item)"
                                >
                                    <RotateCcw :size="15" /> 重新启用
                                </button>
                            </div>
                        </article>
                    </section>
                </div>

                <section v-if="archived.length" class="archive-section">
                    <div class="list-heading">
                        <div>
                            <span class="mini-label">ARCHIVE / 30 DAYS</span>
                            <h2>近期旧监测周期</h2>
                        </div>
                        <span>改址或重新启用后的旧记录</span>
                    </div>
                    <div class="archive-list">
                        <div v-for="item in archived" :key="item.id">
                            <strong>{{ item.name }}</strong
                            ><span>{{ item.url }}</span
                            ><time>{{ dateLabel(item.archived_at) }}</time>
                        </div>
                    </div>
                </section>
            </main>
            <footer>
                <span>校园服务状态 · 管理控制台</span
                ><span>管理操作只对授权账号开放</span>
            </footer>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Noto+Sans+SC:wght@400;500;600;700;800&display=swap');
.admin-page {
    --ink: #19352b;
    --muted: #87988c;
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
.site-header {
    height: 96px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--line);
}
.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -0.04em;
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
.brand-dot,
.page-heading h1 span {
    color: #64a87a;
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
.back-link {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #4e755e;
    font-size: 12px;
    text-decoration: none;
}
.admin-links {
    display: flex;
    align-items: center;
    gap: 20px;
}
.admin-links > a {
    color: #718c78;
    font-size: 12px;
    text-decoration: none;
    white-space: nowrap;
}
.admin-links > a.current {
    color: #286c4b;
    font-weight: 700;
}
.admin-links > a:hover {
    text-decoration: underline;
}
.back-link:hover {
    text-decoration: underline;
}
.page-heading {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 24px;
    padding: 62px 0 38px;
}
.eyebrow,
.mini-label {
    font:
        10px 'DM Mono',
        monospace;
    letter-spacing: 0.16em;
    font-weight: 500;
    color: #6a9a79;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 9px;
}
.eyebrow span {
    width: 18px;
    height: 1px;
    background: #6a9a79;
}
.page-heading h1 {
    font-size: clamp(34px, 4.5vw, 54px);
    letter-spacing: -0.075em;
    line-height: 1.16;
    margin: 18px 0 12px;
}
.page-heading p {
    color: #819287;
    margin: 0;
    font-size: 13px;
}
.live-count {
    text-align: right;
    border-left: 1px solid #dce6dc;
    padding-left: 26px;
}
.live-count strong {
    display: block;
    font:
        500 42px 'DM Mono',
        monospace;
    line-height: 1;
    color: #2f7856;
}
.live-count span {
    display: block;
    margin-top: 9px;
    color: #92a096;
    font-size: 11px;
}
.notice {
    display: flex;
    align-items: center;
    gap: 9px;
    background: #e9f5e9;
    color: #287348;
    border: 1px solid #d6ead9;
    padding: 13px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 12px;
}
.content-grid {
    display: grid;
    grid-template-columns: minmax(300px, 380px) minmax(0, 1fr);
    align-items: start;
    gap: 30px;
}
.form-card,
.service-card,
.empty-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 16px;
    box-shadow: 0 9px 28px rgba(21, 65, 39, 0.035);
}
.form-card {
    padding: 26px;
    position: sticky;
    top: 24px;
}
.card-title {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 27px;
}
.card-title > svg {
    margin-left: auto;
    color: #82a88b;
}
.section-index {
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
.card-title h2,
.list-heading h2 {
    font-size: 21px;
    letter-spacing: -0.04em;
    margin: 5px 0 0;
}
.form-card label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
    margin: 21px 0 9px;
}
.form-card label span {
    font-size: 10px;
    font-weight: 500;
    color: #a6b1a9;
}
.form-card input,
.form-card textarea {
    width: 100%;
    background: #fbfcfa;
    border: 1px solid #dce6dc;
    border-radius: 9px;
    outline: 0;
    padding: 12px 13px;
    color: #244534;
    font:
        12px 'Noto Sans SC',
        sans-serif;
    box-sizing: border-box;
}
.form-card input:focus,
.form-card textarea:focus {
    border-color: #4eaa76;
    box-shadow: 0 0 0 3px #e6f4e8;
}
.form-card textarea {
    resize: vertical;
}
.form-card input::placeholder,
.form-card textarea::placeholder {
    color: #b4bfb6;
}
.field-error {
    color: #bc5f41;
    font-size: 11px;
    margin: 6px 0 0;
}
.form-help {
    display: flex;
    gap: 10px;
    color: #73937c;
    background: #f1f7f1;
    border-radius: 9px;
    padding: 13px;
    margin-top: 22px;
    font-size: 11px;
    line-height: 1.65;
}
.form-help svg {
    flex: none;
    margin-top: 2px;
}
.form-actions {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-top: 24px;
}
.primary-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #225e43;
    border: 0;
    border-radius: 9px;
    color: white;
    font-weight: 700;
    font-size: 12px;
    padding: 13px 17px;
    cursor: pointer;
}
.primary-button:hover {
    background: #174c35;
}
.primary-button:disabled {
    opacity: 0.6;
    cursor: wait;
}
.text-button {
    display: flex;
    align-items: center;
    gap: 4px;
    background: none;
    border: 0;
    color: #728c79;
    font-size: 11px;
    cursor: pointer;
}
.service-section {
    min-width: 0;
}
.list-heading {
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 16px;
    margin-bottom: 16px;
}
.list-heading > span {
    color: #a1aea4;
    font-size: 11px;
}
.service-card {
    padding: 22px 24px;
    margin-bottom: 13px;
}
.service-card.disabled {
    background: #fdfefd;
}
.service-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.number {
    font:
        11px 'DM Mono',
        monospace;
    color: #b0c6b7;
}
.service-status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-weight: 700;
    padding: 5px 8px;
    border-radius: 5px;
}
.service-status.on {
    color: #33805b;
    background: #e8f6ed;
}
.service-status.off {
    color: #7e8e83;
    background: #eff2ef;
}
.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.service-card h3 {
    font-size: 17px;
    margin: 11px 0 3px;
    letter-spacing: -0.03em;
}
.description {
    margin: 0 0 16px;
    color: #9aa89d;
    font-size: 11px;
}
.url {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #3e8260;
    text-decoration: none;
    font:
        11px 'DM Mono',
        monospace;
    overflow-wrap: anywhere;
}
.url:hover {
    text-decoration: underline;
}
.card-actions {
    display: flex;
    gap: 12px;
    border-top: 1px solid #eef2ec;
    padding-top: 15px;
    margin-top: 19px;
}
.card-actions button {
    display: flex;
    align-items: center;
    gap: 5px;
    background: none;
    border: 0;
    color: #688771;
    font-size: 11px;
    cursor: pointer;
    padding: 0;
}
.card-actions button:hover {
    color: #235b40;
}
.empty-card {
    padding: 36px;
    color: #87998a;
    font-size: 12px;
}
.archive-section {
    margin-top: 47px;
    padding-bottom: 60px;
}
.archive-list {
    border: 1px solid var(--line);
    border-radius: 14px;
    background: #fff;
    padding: 4px 22px;
}
.archive-list > div {
    display: grid;
    grid-template-columns: 1fr 2fr auto;
    gap: 20px;
    align-items: center;
    border-bottom: 1px solid #eef2ed;
    padding: 15px 0;
    font-size: 11px;
}
.archive-list > div:last-child {
    border: 0;
}
.archive-list strong {
    font-weight: 700;
}
.archive-list span {
    color: #7e9383;
    overflow-wrap: anywhere;
}
.archive-list time {
    color: #a5b1a5;
    font:
        10px 'DM Mono',
        monospace;
}
footer {
    border-top: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 24px 0 35px;
    color: #9daea1;
    font-size: 11px;
}
@media (max-width: 850px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
    .form-card {
        position: static;
    }
    .page-heading {
        padding-top: 46px;
    }
    .archive-list > div {
        grid-template-columns: 1fr auto;
    }
    .archive-list span {
        grid-column: 1 / -1;
        grid-row: 2;
    }
}
@media (max-width: 560px) {
    .shell {
        padding: 0 17px;
    }
    .site-header {
        height: 76px;
    }
    .brand {
        font-size: 14px;
    }
    .brand small {
        font-size: 8px;
    }
    .admin-links {
        gap: 10px;
    }
    .admin-links > a {
        font-size: 10px;
    }
    .admin-links > .back-link {
        font-size: 0;
        gap: 0;
    }
    .back-link svg {
        width: 20px;
        height: 20px;
    }
    .page-heading {
        align-items: start;
    }
    .page-heading h1 {
        font-size: 33px;
    }
    .live-count {
        display: none;
    }
    .list-heading > span {
        display: none;
    }
    .form-card {
        padding: 21px;
    }
    .service-card {
        padding: 19px;
    }
    footer {
        flex-direction: column;
        gap: 6px;
    }
}
</style>
