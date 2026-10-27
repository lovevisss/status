<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    Check,
    CirclePause,
    Mail,
    Plus,
    RotateCcw,
    Save,
    Send,
    ShieldCheck,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';

type TestResult = {
    status: string;
    last_error: string | null;
    platform_task_id: string | null;
    created_at: string;
};
type Recipient = {
    id: number;
    person_id: string;
    email: string | null;
    is_enabled: boolean;
    last_test: TestResult | null;
};

const props = defineProps<{
    recipients: Recipient[];
    message: string | null;
}>();

const editingId = ref<number | null>(null);
const testingId = ref<number | null>(null);
const testError = ref('');
const form = useForm({ person_id: '', email: '' });
const activeCount = computed(
    () => props.recipients.filter((recipient) => recipient.is_enabled).length,
);

function resetForm() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

function edit(recipient: Recipient) {
    editingId.value = recipient.id;
    form.person_id = recipient.person_id;
    form.email = recipient.email ?? '';
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function submit() {
    const options = { preserveScroll: true, onSuccess: resetForm };
    if (editingId.value === null) {
        form.post('/admin/recipients', options);
    } else {
        form.put(`/admin/recipients/${editingId.value}`, options);
    }
}

function toggle(recipient: Recipient) {
    testError.value = '';
    const action = recipient.is_enabled ? 'disable' : 'enable';
    router.post(
        `/admin/recipients/${recipient.id}/${action}`,
        {},
        { preserveScroll: true },
    );
}

function sendTest(recipient: Recipient) {
    testError.value = '';
    testingId.value = recipient.id;
    router.post(
        `/admin/recipients/${recipient.id}/test`,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                testError.value = errors.test ?? '测试邮件未能加入队列。';
            },
            onFinish: () => {
                testingId.value = null;
            },
        },
    );
}

const statusLabel = (status: string) =>
    ({
        pending: '等待提交',
        queued: '平台排队中',
        processing: '平台处理中',
        accepted: 'SMTP 已接受',
        failed: '投递失败',
        cancelled: '已取消',
    })[status] ?? status;

const dateLabel = (value: string) =>
    new Intl.DateTimeFormat('zh-CN', {
        timeZone: 'Asia/Shanghai',
        month: 'numeric',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
</script>

<template>
    <Head title="邮件收件人管理" />
    <div class="admin-page">
        <div class="top-line"></div>
        <div class="shell">
            <header class="site-header">
                <div class="brand">
                    <span class="brand-icon"><Activity :size="22" /></span>
                    <span
                        >校园服务状态<span class="brand-dot">.</span
                        ><small>MONITOR CONTROL</small></span
                    >
                </div>
                <nav aria-label="管理导航">
                    <a href="/admin/monitors">监测服务</a>
                    <a
                        class="current"
                        href="/admin/recipients"
                        aria-current="page"
                        >邮件收件人</a
                    >
                    <a href="/admin/maintenance">计划维护</a>
                    <a href="/admin/administrators">管理员</a>
                    <a href="/"><ArrowLeft :size="15" /> 公开状态页</a>
                </nav>
            </header>

            <main>
                <div class="page-heading">
                    <div>
                        <span class="eyebrow"
                            ><span></span> ADMIN CONSOLE / 02</span
                        >
                        <h1>邮件收件人<span>.</span></h1>
                        <p>
                            管理故障与恢复邮件的通知名单。变更只影响之后发生的故障。
                        </p>
                    </div>
                    <div class="live-count">
                        <strong>{{
                            activeCount.toString().padStart(2, '0')
                        }}</strong
                        ><span>人正在接收通知</span>
                    </div>
                </div>

                <div v-if="message" class="notice" role="status">
                    <Check :size="17" /> {{ message }}
                </div>
                <div v-if="testError" class="notice error" role="alert">
                    {{ testError }}
                </div>

                <div class="content-grid">
                    <section class="form-card" aria-label="添加或编辑收件人">
                        <div class="card-title">
                            <span class="section-index">01</span>
                            <div>
                                <span class="mini-label"
                                    >NOTIFICATION TARGET</span
                                >
                                <h2>
                                    {{
                                        editingId === null
                                            ? '添加收件人'
                                            : '编辑收件人'
                                    }}
                                </h2>
                            </div>
                            <Plus v-if="editingId === null" :size="20" /><Save
                                v-else
                                :size="20"
                            />
                        </div>
                        <form @submit.prevent="submit">
                            <label for="person-id"
                                >学工号 <span>必填</span></label
                            >
                            <input
                                id="person-id"
                                v-model="form.person_id"
                                maxlength="120"
                                required
                                autocomplete="off"
                                placeholder="例如：20230001"
                            />
                            <p v-if="form.errors.person_id" class="field-error">
                                {{ form.errors.person_id }}
                            </p>

                            <label for="recipient-email"
                                >覆盖邮箱 <span>选填</span></label
                            >
                            <input
                                id="recipient-email"
                                v-model="form.email"
                                type="email"
                                maxlength="255"
                                autocomplete="off"
                                placeholder="留空则使用平台同步的邮箱"
                            />
                            <p v-if="form.errors.email" class="field-error">
                                {{ form.errors.email }}
                            </p>

                            <div class="form-help">
                                <ShieldCheck :size="16" /><span
                                    >学工号须存在于消息平台人员快照中。留空邮箱时使用平台同步地址；填写后覆盖该地址。</span
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
                                              ? '添加收件人'
                                              : '保存更改'
                                    }}
                                </button>
                                <button
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

                    <section class="recipient-section" aria-label="通知名单">
                        <div class="list-heading">
                            <div>
                                <span class="mini-label"
                                    >RECIPIENTS /
                                    {{
                                        recipients.length
                                            .toString()
                                            .padStart(2, '0')
                                    }}</span
                                >
                                <h2>通知名单</h2>
                            </div>
                            <span>已停用人员不参与新故障通知</span>
                        </div>
                        <div v-if="recipients.length === 0" class="empty-card">
                            <Mail :size="25" /><strong>尚无收件人</strong
                            ><span
                                >添加学工号后，新的故障才能生成通知邮件。</span
                            >
                        </div>
                        <article
                            v-for="recipient in recipients"
                            :key="recipient.id"
                            class="recipient-card"
                            :class="{ disabled: !recipient.is_enabled }"
                        >
                            <div class="recipient-head">
                                <span class="recipient-id">{{
                                    recipient.person_id
                                }}</span
                                ><span
                                    class="state"
                                    :class="recipient.is_enabled ? 'on' : 'off'"
                                    ><span></span
                                    >{{
                                        recipient.is_enabled
                                            ? '接收通知'
                                            : '已停用'
                                    }}</span
                                >
                            </div>
                            <p class="recipient-email">
                                {{ recipient.email || '使用平台同步邮箱' }}
                            </p>
                            <div
                                v-if="recipient.last_test"
                                class="test-result"
                                :class="
                                    recipient.last_test.status === 'failed'
                                        ? 'failed'
                                        : ''
                                "
                            >
                                <span
                                    >最近测试 ·
                                    {{
                                        dateLabel(
                                            recipient.last_test.created_at,
                                        )
                                    }}</span
                                >
                                <strong>{{
                                    statusLabel(recipient.last_test.status)
                                }}</strong>
                                <small v-if="recipient.last_test.last_error">{{
                                    recipient.last_test.last_error
                                }}</small>
                                <small
                                    v-else-if="
                                        recipient.last_test.platform_task_id
                                    "
                                    >任务
                                    {{
                                        recipient.last_test.platform_task_id
                                    }}</small
                                >
                            </div>
                            <div class="card-actions">
                                <button type="button" @click="edit(recipient)">
                                    编辑
                                </button>
                                <button
                                    type="button"
                                    @click="toggle(recipient)"
                                >
                                    <CirclePause
                                        v-if="recipient.is_enabled"
                                        :size="15"
                                    /><RotateCcw v-else :size="15" />{{
                                        recipient.is_enabled
                                            ? '停用'
                                            : '重新启用'
                                    }}
                                </button>
                                <button
                                    v-if="recipient.is_enabled"
                                    type="button"
                                    :disabled="testingId === recipient.id"
                                    @click="sendTest(recipient)"
                                >
                                    <Send :size="15" />{{
                                        testingId === recipient.id
                                            ? '提交中…'
                                            : '发送测试邮件'
                                    }}
                                </button>
                            </div>
                        </article>
                    </section>
                </div>
            </main>
            <footer>
                <span>校园服务状态 · 邮件通知管理</span
                ><span>测试邮件由队列异步发送；SMTP 接受不等于进入收件箱</span>
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
.site-header nav {
    display: flex;
    align-items: center;
    gap: 20px;
}
.site-header nav a {
    display: flex;
    align-items: center;
    gap: 5px;
    color: #718c78;
    font-size: 12px;
    text-decoration: none;
    white-space: nowrap;
}
.site-header nav a.current {
    color: #286c4b;
    font-weight: 700;
}
.site-header nav a:hover {
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
.notice.error {
    background: #fff1e9;
    color: #a85638;
    border-color: #f1d8ca;
}
.content-grid {
    display: grid;
    grid-template-columns: minmax(300px, 380px) minmax(0, 1fr);
    align-items: start;
    gap: 30px;
}
.form-card,
.recipient-card,
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
.form-card input {
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
.form-card input:focus {
    border-color: #4eaa76;
    box-shadow: 0 0 0 3px #e6f4e8;
}
.form-card input::placeholder {
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
.recipient-section {
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
.recipient-card {
    padding: 22px 24px;
    margin-bottom: 13px;
}
.recipient-card.disabled {
    background: #fdfefd;
}
.recipient-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.recipient-id {
    font:
        500 17px 'DM Mono',
        monospace;
    color: #214d39;
    overflow-wrap: anywhere;
}
.state {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 10px;
    font-weight: 700;
    padding: 5px 8px;
    border-radius: 5px;
    white-space: nowrap;
}
.state.on {
    color: #33805b;
    background: #e8f6ed;
}
.state.off {
    color: #7e8e83;
    background: #eff2ef;
}
.state span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}
.recipient-email {
    margin: 9px 0 0;
    color: #8ea193;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.test-result {
    display: flex;
    flex-wrap: wrap;
    gap: 5px 12px;
    margin-top: 18px;
    padding: 11px 12px;
    border-radius: 8px;
    background: #f2f7f1;
    font-size: 11px;
    color: #728e79;
}
.test-result strong {
    color: #2c7950;
}
.test-result.failed {
    background: #fff1e9;
    color: #aa705a;
}
.test-result.failed strong {
    color: #b7593a;
}
.test-result small {
    flex-basis: 100%;
    overflow-wrap: anywhere;
    color: inherit;
}
.card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 18px;
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
.card-actions button:disabled {
    opacity: 0.5;
    cursor: wait;
}
.empty-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 9px;
    padding: 44px 25px;
    text-align: center;
    color: #8ba18f;
    font-size: 12px;
}
.empty-card strong {
    font-size: 16px;
    color: #244936;
}
footer {
    border-top: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding: 24px 0 35px;
    margin-top: 60px;
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
    .live-count {
        display: none;
    }
}
@media (max-width: 620px) {
    .shell {
        padding: 0 17px;
    }
    .site-header {
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
    .site-header nav {
        gap: 10px;
        width: 100%;
        justify-content: space-between;
    }
    .site-header nav a {
        font-size: 10px;
    }
    .site-header nav a:last-child {
        font-size: 0;
    }
    .site-header nav a:last-child svg {
        width: 19px;
        height: 19px;
    }
    .page-heading h1 {
        font-size: 33px;
    }
    .list-heading > span {
        display: none;
    }
    .form-card {
        padding: 21px;
    }
    .recipient-card {
        padding: 19px;
    }
    footer {
        flex-direction: column;
        gap: 6px;
    }
}
</style>
