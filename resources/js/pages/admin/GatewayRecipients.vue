<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
type Test = {
    channel: string;
    status: string;
    last_error: string | null;
    platform_task_id: string | null;
    created_at: string;
};
type Person = {
    id: number;
    person_id: string;
    email: string | null;
    mobile: string | null;
    notify_email: boolean;
    notify_sms: boolean;
    is_enabled: boolean;
    tests: Test[];
};
type RecentAlert = {
    request_id: string;
    recipient_id: string;
    channel: string;
    kind: string;
    title: string;
    content: string;
    status: string;
    last_error: string | null;
    created_at: string;
    mobile_override: boolean;
};
defineProps<{
    recipients: Person[];
    recentAlerts: RecentAlert[];
    message: string | null;
}>();
const editing = ref<number | null>(null);
const testError = ref('');
const form = useForm({
    person_id: '',
    email: '',
    mobile: '',
    notify_email: true,
    notify_sms: false,
});
function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}
function edit(person: Person) {
    editing.value = person.id;
    form.person_id = person.person_id;
    form.email = person.email ?? '';
    form.mobile = person.mobile ?? '';
    form.notify_email = person.notify_email;
    form.notify_sms = person.notify_sms;
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function save() {
    const opts = { preserveScroll: true, onSuccess: reset };
    if (editing.value === null) form.post('/admin/gateway-recipients', opts);
    else form.put(`/admin/gateway-recipients/${editing.value}`, opts);
}
function toggle(person: Person) {
    router.post(
        `/admin/gateway-recipients/${person.id}/${person.is_enabled ? 'disable' : 'enable'}`,
        {},
        { preserveScroll: true },
    );
}
function test(person: Person) {
    testError.value = '';
    router.post(
        `/admin/gateway-recipients/${person.id}/test`,
        {},
        {
            preserveScroll: true,
            onError: (errors) =>
                (testError.value = errors.test ?? '测试通知创建失败'),
        },
    );
}
</script>
<template>
    <Head title="网关处理人员" />
    <div class="page">
        <div class="shell">
            <header>
                <a class="brand" href="/">校园服务状态<span>.</span></a>
                <nav aria-label="管理导航">
                    <a href="/admin/monitors">监测服务</a
                    ><a href="/admin/gateways">电表网关</a
                    ><a class="current" href="/admin/gateway-recipients"
                        >网关处理人员</a
                    ><a href="/admin/recipients">网站收件人</a
                    ><a href="/admin/maintenance">计划维护</a
                    ><a href="/admin/administrators">管理员</a>
                </nav>
            </header>
            <main>
                <div class="heading">
                    <small>ADMIN CONSOLE / ALERT ROUTING</small>
                    <h1>网关处理人员</h1>
                    <p>
                        所有宿舍电表网关共用这组人员；按每人选择的渠道发送故障与恢复通知。
                    </p>
                </div>
                <p v-if="message" class="notice" role="status">{{ message }}</p>
                <p v-if="testError" class="error" role="alert">
                    {{ testError }}
                </p>
                <form class="editor" @submit.prevent="save">
                    <h2>
                        {{ editing === null ? '添加处理人员' : '编辑处理人员' }}
                    </h2>
                    <div class="fields">
                        <label
                            >学工号<input
                                v-model="form.person_id"
                                required
                            /><small>{{ form.errors.person_id }}</small></label
                        ><label
                            >邮箱（可选）<input
                                v-model="form.email"
                                type="email"
                            /><small>{{ form.errors.email }}</small></label
                        ><label
                            >手机号（可选）<input
                                v-model="form.mobile"
                                inputmode="tel"
                                placeholder="不填则使用平台快照"
                            /><small>{{ form.errors.mobile }}</small></label
                        >
                    </div>
                    <div class="channels">
                        <label
                            ><input
                                v-model="form.notify_email"
                                type="checkbox"
                            />
                            邮件提醒</label
                        ><label
                            ><input v-model="form.notify_sms" type="checkbox" />
                            短信提醒</label
                        ><small>{{ form.errors.notify_email }}</small>
                    </div>
                    <p class="hint">
                        学工号必须存在于消息平台人员快照；邮箱和手机号留空时使用平台记录。
                    </p>
                    <div class="actions">
                        <button type="submit" :disabled="form.processing">
                            {{
                                editing === null ? '添加人员' : '保存更改'
                            }}</button
                        ><button
                            v-if="editing !== null"
                            type="button"
                            class="secondary"
                            @click="reset"
                        >
                            取消
                        </button>
                    </div>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>学工号</th>
                                <th>邮箱</th>
                                <th>手机号</th>
                                <th>通知渠道</th>
                                <th>状态</th>
                                <th>最近测试</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="person in recipients" :key="person.id">
                                <td class="mono">{{ person.person_id }}</td>
                                <td>{{ person.email || '平台快照' }}</td>
                                <td>{{ person.mobile || '平台快照' }}</td>
                                <td>
                                    {{
                                        [
                                            person.notify_email ? '邮件' : '',
                                            person.notify_sms ? '短信' : '',
                                        ]
                                            .filter(Boolean)
                                            .join(' + ')
                                    }}
                                </td>
                                <td
                                    :class="
                                        person.is_enabled
                                            ? 'enabled'
                                            : 'disabled'
                                    "
                                >
                                    {{
                                        person.is_enabled ? '已启用' : '已停用'
                                    }}
                                </td>
                                <td>
                                    <div
                                        v-for="item in person.tests"
                                        :key="item.channel"
                                        :title="item.last_error ?? ''"
                                    >
                                        {{
                                            item.channel === 'sms'
                                                ? '短信'
                                                : '邮件'
                                        }}：{{ item.status
                                        }}<small v-if="item.last_error">{{
                                            item.last_error
                                        }}</small>
                                    </div>
                                    <span v-if="!person.tests.length">—</span>
                                </td>
                                <td>
                                    <button type="button" @click="edit(person)">
                                        编辑</button
                                    ><button
                                        type="button"
                                        @click="toggle(person)"
                                    >
                                        {{
                                            person.is_enabled ? '停用' : '启用'
                                        }}</button
                                    ><button
                                        type="button"
                                        :disabled="!person.is_enabled"
                                        @click="test(person)"
                                    >
                                        测试
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!recipients.length">
                                <td colspan="7" class="empty">
                                    尚未添加网关处理人员
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <section class="alert-history" aria-label="最近网关通知">
                    <h2>最近网关通知</h2>
                    <p>
                        可查看实际提交的短信或邮件正文及平台返回的错误；不显示手机号和密钥。
                    </p>
                    <div v-if="!recentAlerts.length" class="empty">
                        暂无通知记录
                    </div>
                    <details
                        v-for="item in recentAlerts"
                        :key="item.request_id"
                        class="alert-entry"
                    >
                        <summary>
                            <strong
                                >{{ item.channel === 'sms' ? '短信' : '邮件' }}
                                ·
                                {{
                                    item.kind === 'test'
                                        ? '测试'
                                        : item.kind === 'outage'
                                          ? '故障'
                                          : '恢复'
                                }}</strong
                            >
                            <span>学工号 {{ item.recipient_id }}</span>
                            <span
                                :class="item.status === 'failed' ? 'error' : ''"
                                >{{ item.status }}</span
                            >
                            <time>{{
                                new Date(item.created_at).toLocaleString(
                                    'zh-CN',
                                    { timeZone: 'Asia/Shanghai' },
                                )
                            }}</time>
                        </summary>
                        <div class="alert-detail">
                            <p><b>标题：</b>{{ item.title }}</p>
                            <p><b>正文：</b>{{ item.content }}</p>
                            <p>
                                <b>手机号覆盖：</b
                                >{{
                                    item.mobile_override
                                        ? '是'
                                        : '否，使用平台快照'
                                }}
                            </p>
                            <p>
                                <b>请求编号：</b
                                ><code>{{ item.request_id }}</code>
                            </p>
                            <p v-if="item.last_error" class="error">
                                <b>平台错误：</b>{{ item.last_error }}
                            </p>
                        </div>
                    </details>
                </section>
            </main>
        </div>
    </div>
</template>
<style scoped>
.page {
    min-height: 100vh;
    background: #f7f9f6;
    color: #18302b;
    font-family: 'Noto Sans SC', sans-serif;
    border-top: 5px solid #26644b;
}
.shell {
    max-width: 1180px;
    margin: auto;
    padding: 0 30px;
}
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 30px;
    min-height: 90px;
    border-bottom: 1px solid #dde7df;
}
.brand {
    font-size: 19px;
    font-weight: 800;
    color: #18302b;
    text-decoration: none;
    white-space: nowrap;
}
.brand span {
    color: #4eaa76;
}
nav {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
}
nav a {
    color: #6c8073;
    text-decoration: none;
    font-size: 12px;
    font-weight: 700;
}
nav a.current {
    color: #1f7952;
}
main {
    padding: 55px 0 80px;
}
.heading {
    margin-bottom: 30px;
}
.heading small {
    font-size: 11px;
    letter-spacing: 0.16em;
    color: #4a9872;
}
.heading h1 {
    font-size: 42px;
    letter-spacing: -0.05em;
    margin: 10px 0;
}
.heading p,
.hint {
    color: #758a7d;
}
.notice {
    padding: 15px 18px;
    border-radius: 10px;
    background: #e5f4e9;
    color: #24764d;
}
.error {
    color: #b74737;
}
.editor,
.table-wrap {
    background: #fff;
    border: 1px solid #e2e9e3;
    border-radius: 15px;
}
.editor {
    padding: 25px;
    margin-bottom: 24px;
}
.editor h2 {
    font-size: 20px;
    margin: 0 0 20px;
}
.fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
label {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
}
input:not([type='checkbox']) {
    border: 1px solid #d5e1d8;
    border-radius: 8px;
    padding: 11px;
    font: inherit;
}
label small {
    color: #b74737;
    font-weight: 400;
}
.channels {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-top: 20px;
}
.channels label {
    flex-direction: row;
    align-items: center;
}
.channels small {
    color: #b74737;
}
.hint {
    font-size: 12px;
}
.actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}
button {
    border: 1px solid #c8dacf;
    background: #fff;
    border-radius: 7px;
    padding: 8px 12px;
    color: #2a6748;
    font-weight: 700;
    cursor: pointer;
    margin-right: 5px;
}
.actions button:first-child {
    background: #246d4d;
    color: #fff;
}
.actions .secondary {
    background: #fff;
    color: #2a6748;
}
.table-wrap {
    overflow-x: auto;
}
table {
    border-collapse: collapse;
    width: 100%;
    min-width: 960px;
}
th,
td {
    text-align: left;
    padding: 17px 14px;
    border-bottom: 1px solid #edf1eb;
    font-size: 12px;
    vertical-align: top;
}
th {
    background: #f0f5ef;
    color: #778b7d;
    font-size: 11px;
}
.mono {
    font-family: monospace;
}
.enabled {
    color: #268058;
}
.disabled {
    color: #87948c;
}
.empty {
    text-align: center;
    color: #87948c;
}
.alert-history {
    margin-top: 38px;
}
.alert-history h2 {
    margin: 0 0 8px;
    font-size: 21px;
}
.alert-history > p {
    color: #758a7d;
    font-size: 12px;
    margin-bottom: 18px;
}
.alert-entry {
    background: #fff;
    border: 1px solid #e2e9e3;
    border-radius: 10px;
    margin: 8px 0;
}
.alert-entry summary {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    padding: 15px 18px;
    font-size: 12px;
    color: #687f70;
}
.alert-entry summary strong {
    color: #18302b;
    min-width: 100px;
}
.alert-entry summary time {
    margin-left: auto;
}
.alert-detail {
    border-top: 1px solid #edf1eb;
    padding: 12px 18px;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.alert-detail p {
    margin: 9px 0;
}
td small {
    display: block;
    color: #b74737;
    max-width: 170px;
    overflow-wrap: anywhere;
}
@media (max-width: 800px) {
    header {
        align-items: start;
        flex-direction: column;
        padding: 20px 0;
    }
    .fields {
        grid-template-columns: 1fr;
    }
}
</style>
