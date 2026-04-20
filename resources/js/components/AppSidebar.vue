<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar, SidebarContent, SidebarFooter, SidebarHeader,
    SidebarMenu, SidebarMenuButton, SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Banknote, BarChart3, Bell, BookOpen, CheckCircle2, ClipboardList,
    CreditCard, GraduationCap, History, LayoutGrid, Receipt, Settings, Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const safeRoute = (name: string, params?: any): string => {
    try { return route(name, params); } catch { return '#'; }
};

const page = usePage();
const userRole = computed(() => (page.props.auth as any)?.user?.role ?? 'student');

const mainNavItems = computed<NavItem[]>(() => {
    const role = userRole.value;
    const items: NavItem[] = [
        // ── Student ──
        { title: 'Dashboard',           href: safeRoute('student.dashboard'),   icon: LayoutGrid,   roles: ['student'] },
        { title: 'My Account',          href: safeRoute('student.account'),     icon: CreditCard,   roles: ['student'] },
        { title: 'Transaction History', href: safeRoute('transactions.index'),  icon: History,      roles: ['student'] },

        // ── Admin ──
        { title: 'Admin Dashboard',     href: safeRoute('admin.dashboard'),     icon: LayoutGrid,   roles: ['admin'] },
        { title: 'Admin Users',         href: safeRoute('users.index'),         icon: Users,        roles: ['admin'] },
        { title: 'Student Management',  href: safeRoute('student-fees.index'),  icon: GraduationCap, roles: ['admin'] },
        { title: 'Student Archive',     href: safeRoute('students.archive'),    icon: GraduationCap, roles: ['admin'] },
        { title: 'Notifications',       href: safeRoute('admin.notifications.index'), icon: Bell,         roles: ['admin'] },
        // { title: 'Subjects',            href: safeRoute('subjects.index'),      icon: BookOpen,     roles: ['admin'] },

        // ── Accounting ──
        { title: 'Accounting Dashboard', href: safeRoute('accounting.dashboard'), icon: Banknote,   roles: ['accounting'] },
        { title: 'Student Fee Management', href: safeRoute('student-fees.index'),  icon: Receipt,      roles: ['accounting'] },
        { title: 'Financial Reports',      href: safeRoute('accounting.financial-reports'), icon: BarChart3, roles: ['accounting'] },
        { title: 'Fee Settings',           href: safeRoute('accounting.fee-settings.index'), icon: Settings, roles: ['accounting'] },
        { title: 'Payment Approvals',      href: safeRoute('approvals.index'),     icon: CheckCircle2, roles: ['accounting', 'admin'] },
    ];
    return items.filter((item) => !item.roles || item.roles.includes(role));
});

const footerNavItems: NavItem[] = [];

</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>