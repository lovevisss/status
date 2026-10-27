<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Activity, ArrowLeft, Check, Plus, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';

type Administrator = {
    id: number;
    cas_account: string;
    is_enabled: boolean;
};

const props = defineProps<{
    administrators: Administrator[];
    currentCasAccount: string | null;
    message: string | null;
}>();

const form = useForm({ cas_account: '' });
const actionError = ref('');
const activeCount = computed(
    () => props.administrators.filter((admin) => admin.is_enabled).length,
);

function addAdministrator() {
    form.post('/admin/administrators', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function toggle(admin: Administrator) {
    actionError.value = '';
    const action = admin.is_enabled ? 'disable' : 'enable';
    router.post(
        `/admin/administrators/${admin.id}/${action}`,
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                actionError.value = errors.administrator ?? '操作未完成。';
            },
        },
    );
}
</script>

<template>
    <Head title="管理员管理" />
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
                    <a href="/admin/recipients">邮件收件人</a>
                    <a href="/admin/maintenance">计划维护</a>
                    <a
                        class="current"
                        href="/admin/administrators"
                        aria-current="page"
                        >管理员</a
                    >
                    <a href="/"><ArrowLeft :size="15" /> 公开状态页</a>
                </nav>
            </header>

            <main>
                <div class="page-heading">
                    <div>
                        <span class="eyebrow"
                            ><span></span> ADMIN CONSOLE / 04</span
                        >
                        <h1>管理员<span>.</span></h1>
                        <p>
                            按 CAS
                            学工号授权后台访问。新增人员首次登录时会建立本站账号。
                        </p>
                    </div>
                    <div class="live-count">
                        <strong>{{
                            activeCount.toString().padStart(2, '0')
                        }}</strong>
                        <span>名 CAS 管理员已启用</span>
                    </div>
                </div>

                <div v-if="message" class="notice" role="status">
                    <Check :size="17" /> {{ message }}
                </div>
                <div v-if="actionError" class="notice error" role="alert">
                    {{ actionError }}
                </div>

                <div class="content-grid">
                    <section
                        class="panel add-panel"
                        aria-labelledby="add-heading"
                    >
                        <div class="panel-heading">
                            <span class="section-index">01</span>
                            <div>
                                <small>GRANT ACCESS</small>
                                <h2 id="add-heading">添加管理员</h2>
                            </div>
                            <Plus :size="20" />
                        </div>
                        <form @submit.prevent="addAdministrator">
                            <label for="cas-account">CAS 学工号</label>
                            <input
                                id="cas-account"
                                v-model="form.cas_account"
                                type="text"
                                maxlength="120"
                                autocomplete="off"
                                pattern="[A-Za-z0-9_-]+"
                                required
                                placeholder="例如：20150301"
                            />
                            <p
                                v-if="form.errors.cas_account"
                                class="field-error"
                                role="alert"
                            >
                                {{ form.errors.cas_account }}
                            </p>
                            <p class="field-note">
                                请核对完整学工号；CAS 登录按原值精确匹配。
                            </p>
                            <button type="submit" :disabled="form.processing">
                                <Plus :size="17" /> 添加并启用
                            </button>
                        </form>
                    </section>

                    <section
                        class="panel roster-panel"
                        aria-labelledby="roster-heading"
                    >
                        <div class="panel-heading">
                            <span class="section-index">02</span>
                            <div>
                                <small>ACCESS ROSTER</small>
                                <h2 id="roster-heading">授权名单</h2>
                            </div>
                            <ShieldCheck :size="20" />
                        </div>
                        <p class="roster-intro">
                            停用后，该账号的现有会话在下次访问后台时失去权限。当前账号不可停用。
                        </p>
                        <ul>
                            <li v-for="admin in administrators" :key="admin.id">
                                <div class="identity">
                                    <span class="avatar">{{
                                        admin.cas_account.slice(-2)
                                    }}</span>
                                    <div>
                                        <strong>{{ admin.cas_account }}</strong>
                                        <span
                                            :class="
                                                admin.is_enabled
                                                    ? 'active'
                                                    : 'inactive'
                                            "
                                            >{{
                                                admin.is_enabled
                                                    ? '已启用'
                                                    : '已停用'
                                            }}</span
                                        >
                                        <small
                                            v-if="
                                                admin.cas_account ===
                                                currentCasAccount
                                            "
                                            >当前账号</small
                                        >
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="toggle"
                                    :disabled="
                                        admin.is_enabled &&
                                        admin.cas_account === currentCasAccount
                                    "
                                    :aria-label="`${admin.is_enabled ? '停用' : '启用'}管理员 ${admin.cas_account}`"
                                    @click="toggle(admin)"
                                >
                                    {{ admin.is_enabled ? '停用' : '启用' }}
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
            </main>
        </div>
    </div>
</template>

<style scoped>
.admin-page {
    min-height: 100vh;
    color: #173b39;
    background: #f7f9f5;
    font-family: 'Noto Sans SC', sans-serif;
}
.top-line {
    height: 5px;
    background: #217a69;
}
.shell {
    width: min(1240px, calc(100% - 48px));
    margin: auto;
}
.site-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 28px 0;
    border-bottom: 1px solid #dce8e0;
}
.brand {
    display: flex;
    align-items: center;
    gap: 11px;
    font-size: 18px;
    font-weight: 800;
    white-space: nowrap;
}
.brand-icon {
    display: grid;
    place-items: center;
    width: 37px;
    height: 37px;
    border-radius: 10px;
    color: #fff;
    background: #217a69;
}
.brand-dot {
    color: #45b689;
}
.brand small {
    display: block;
    margin-top: -2px;
    color: #95aca4;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.16em;
}
nav {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px;
}
nav a {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 9px 12px;
    border-radius: 7px;
    color: #627c76;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}
nav a:hover,
nav a.current {
    color: #126e5c;
    background: #e5f2e9;
}
main {
    padding: 50px 0 88px;
}
.page-heading {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    align-items: end;
    margin-bottom: 32px;
}
.eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #2b947b;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.17em;
}
.eyebrow span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #4db88b;
}
h1 {
    margin: 9px 0 8px;
    font-size: clamp(32px, 5vw, 48px);
    font-weight: 800;
    letter-spacing: -0.055em;
}
h1 span {
    color: #44af84;
}
.page-heading p,
.roster-intro {
    margin: 0;
    color: #78908a;
    font-size: 14px;
    line-height: 1.7;
}
.live-count {
    display: flex;
    align-items: baseline;
    gap: 10px;
    white-space: nowrap;
    color: #7d968c;
    font-size: 12px;
}
.live-count strong {
    color: #217a69;
    font-size: 36px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.content-grid {
    display: grid;
    grid-template-columns: minmax(280px, 0.85fr) minmax(0, 1.45fr);
    gap: 20px;
    align-items: start;
}
.panel {
    padding: 27px;
    border: 1px solid #e4ece6;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 12px 38px #1b493c08;
}
.panel-heading {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-bottom: 26px;
    color: #398d75;
}
.panel-heading > svg {
    margin-left: auto;
}
.section-index {
    align-self: start;
    color: #afc7ba;
    font-size: 11px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}
.panel-heading small {
    color: #9bb2a8;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.13em;
}
h2 {
    margin: 3px 0 0;
    color: #234542;
    font-size: 20px;
    font-weight: 800;
}
label {
    display: block;
    margin-bottom: 10px;
    font-size: 13px;
    font-weight: 800;
}
input {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #cbdcd3;
    border-radius: 8px;
    outline: none;
    color: #173b39;
    font-size: 16px;
    font-variant-numeric: tabular-nums;
}
input:focus {
    border-color: #2b947b;
    box-shadow: 0 0 0 3px #2b947b22;
}
.field-note {
    margin: 11px 0 21px;
    color: #829991;
    font-size: 12px;
    line-height: 1.6;
}
.field-error {
    margin: 9px 0 0;
    color: #b44f45;
    font-size: 12px;
}
form button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 13px;
    border: 0;
    border-radius: 8px;
    color: #fff;
    background: #217a69;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
}
form button:hover {
    background: #146452;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
.roster-intro {
    margin: -9px 0 16px;
    font-size: 12px;
}
ul {
    margin: 0;
    padding: 0;
    list-style: none;
}
li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 16px 0;
    border-top: 1px solid #edf2ee;
}
.identity {
    display: flex;
    align-items: center;
    min-width: 0;
    gap: 13px;
}
.avatar {
    display: grid;
    flex: none;
    place-items: center;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    color: #227962;
    background: #e8f4ed;
    font-size: 13px;
    font-weight: 800;
}
.identity strong {
    display: block;
    color: #214742;
    font-size: 15px;
    letter-spacing: 0.025em;
}
.identity span.active,
.identity span.inactive {
    font-size: 11px;
    font-weight: 700;
}
.active {
    color: #238360;
}
.inactive {
    color: #a08265;
}
.identity small {
    margin-left: 10px;
    color: #8da59a;
    font-size: 11px;
}
.toggle {
    flex: none;
    padding: 8px 12px;
    border: 1px solid #c9ded1;
    border-radius: 7px;
    color: #2a7968;
    background: #fff;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}
.toggle:hover {
    background: #edf6f0;
}
.notice {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 18px;
    padding: 13px 16px;
    border-radius: 8px;
    color: #246d57;
    background: #e6f3e8;
    font-size: 13px;
}
.notice.error {
    color: #ab493f;
    background: #fceee9;
}
@media (max-width: 850px) {
    .site-header,
    .page-heading {
        align-items: start;
        flex-direction: column;
    }
    .content-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 600px) {
    .shell {
        width: min(100% - 28px, 1240px);
    }
    .site-header {
        padding: 19px 0;
    }
    nav {
        gap: 0;
    }
    nav a {
        padding: 8px;
        font-size: 12px;
    }
    main {
        padding-top: 32px;
    }
    .panel {
        padding: 20px;
    }
}
</style>
