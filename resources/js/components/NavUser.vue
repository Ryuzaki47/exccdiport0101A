<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import UserMenuContent from './UserMenuContent.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, ChevronsUpDown } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const user = page.props.auth.user;
const { isMobile, state } = useSidebar();

const unreadCount = computed(() => (page.props as any).unreadNotificationsCount ?? 0);
const isStudent = computed(() => (user as any)?.role === 'student');

// Only show bell for students
const showBell = computed(() => {
    const role = (user as any)?.role;
    return role === 'student';
});

const notifRoute = computed(() => route('student.notifications'));
</script>

<template>
    <SidebarMenu>
        <!-- User Dropdown -->
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                    >
                        <UserInfo :user="user" />
                        
                        <!-- Notification Bell with Badge (inside the button, beside name) -->
                        <Link
                            v-if="showBell"
                            :href="notifRoute"
                            class="relative flex-shrink-0 ml-auto mr-1 transition hover:opacity-70"
                            @click.stop
                        >
                            <Bell class="size-4" />
                            <!-- Unread badge -->
                            <span
                                v-if="unreadCount > 0"
                                class="absolute -top-1.5 -right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white leading-none"
                            >
                                {{ unreadCount > 9 ? '9+' : unreadCount }}
                            </span>
                        </Link>
                        
                        <ChevronsUpDown class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>

                <DropdownMenuContent
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                    :side="isMobile ? 'bottom' : state === 'collapsed' ? 'left' : 'bottom'"
                    align="end"
                    :side-offset="4"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>