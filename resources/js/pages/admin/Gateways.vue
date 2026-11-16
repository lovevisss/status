<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Gateway = {
    id: number;
    gateway_number: string;
    ip: string;
    location: string;
    building: number;
    is_enabled: boolean;
};
const props = defineProps<{ gateways: Gateway[]; message: string | null }>();
const editing = ref<number | null>(null);
const form = useForm({ gateway_number: '', ip: '', location: '', building: 1 });
function edit(item: Gateway) {
    editing.value = item.id;
    form.gateway_number = item.gateway_number;
    form.ip = item.ip;
    form.location = item.location;
    form.building = item.building;
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function save() {
    if (editing.value !== null)
        form.put(`/admin/gateways/${editing.value}`, {
            preserveScroll: true,
            onSuccess: () => (editing.value = null),
        });
}
function toggle(item: Gateway) {
    router.post(
        `/admin/gateways/${item.id}/${item.is_enabled ? 'disable' : 'enable'}`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="电表网关管理" />
    <div class="page">
        <div class="shell">
            <header>
                <a class="brand" href="/">校园服务状态<span>.</span></a>
                <nav aria-label="管理导航">
                    <a href="/admin/monitors">监测服务</a
                    ><a class="current" href="/admin/gateways">电表网关</a
                    ><a href="/admin/gateway-recipients">网关处理人员</a
                    ><a href="/admin/recipients">网站收件人</a
                    ><a href="/admin/maintenance">计划维护</a
                    ><a href="/admin/administrators">管理员</a>
                </nav>
            </header>
            <main>
                <div class="heading">
                    <div>
                        <small>ADMIN CONSOLE / GATEWAYS</small>
                        <h1>宿舍电表网关</h1>
                        <p>
                            管理网关编号、IP 和安装位置；修改 IP
                            或重新启用会开始新的监测周期。
                        </p>
                    </div>
                    <strong
                        >{{ props.gateways.filter((x) => x.is_enabled).length }}
                        <span>台监测中</span></strong
                    >
                </div>
                <p v-if="message" class="notice" role="status">{{ message }}</p>
                <form
                    v-if="editing !== null"
                    class="editor"
                    @submit.prevent="save"
                >
                    <h2>编辑网关</h2>
                    <div class="fields">
                        <label
                            >网关号<input
                                v-model="form.gateway_number"
                                required
                            /><small>{{
                                form.errors.gateway_number
                            }}</small></label
                        ><label
                            >楼栋<input
                                v-model.number="form.building"
                                type="number"
                                min="1"
                                max="99"
                                required
                            /><small>{{ form.errors.building }}</small></label
                        ><label
                            >网关 IP<input
                                v-model="form.ip"
                                required
                            /><small>{{ form.errors.ip }}</small></label
                        ><label
                            >安装位置<input
                                v-model="form.location"
                                required
                            /><small>{{ form.errors.location }}</small></label
                        >
                    </div>
                    <div class="actions">
                        <button type="submit" :disabled="form.processing">
                            保存更改</button
                        ><button
                            type="button"
                            class="secondary"
                            @click="editing = null"
                        >
                            取消
                        </button>
                    </div>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>楼栋</th>
                                <th>网关号</th>
                                <th>IP</th>
                                <th>安装位置</th>
                                <th>监测</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in gateways" :key="item.id">
                                <td>{{ item.building }} 号楼</td>
                                <td class="mono">{{ item.gateway_number }}</td>
                                <td class="mono">{{ item.ip }}</td>
                                <td>{{ item.location }}</td>
                                <td>
                                    <span
                                        :class="
                                            item.is_enabled
                                                ? 'enabled'
                                                : 'disabled'
                                        "
                                        >{{
                                            item.is_enabled
                                                ? '已启用'
                                                : '已停用'
                                        }}</span
                                    >
                                </td>
                                <td>
                                    <button type="button" @click="edit(item)">
                                        编辑</button
                                    ><button
                                        type="button"
                                        @click="toggle(item)"
                                    >
                                        {{ item.is_enabled ? '停用' : '启用' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
    display: flex;
    justify-content: space-between;
    align-items: end;
    gap: 20px;
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
.heading p {
    color: #758a7d;
    margin: 0;
}
.heading > strong {
    font-size: 36px;
    white-space: nowrap;
}
.heading > strong span {
    font-size: 12px;
    font-weight: 400;
}
.notice {
    padding: 15px 18px;
    border-radius: 10px;
    background: #e5f4e9;
    color: #24764d;
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
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}
label {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 12px;
    font-weight: 700;
}
input {
    border: 1px solid #d5e1d8;
    border-radius: 8px;
    padding: 11px;
    font: inherit;
}
label small {
    color: #b74737;
    font-weight: 400;
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
    min-width: 760px;
}
th,
td {
    text-align: left;
    padding: 17px 18px;
    border-bottom: 1px solid #edf1eb;
    font-size: 13px;
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
@media (max-width: 800px) {
    header,
    .heading {
        align-items: start;
        flex-direction: column;
        padding: 20px 0;
    }
    .fields {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
