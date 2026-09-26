import { defineStore } from 'pinia'
import http from '@/support/http'
import { rememberAuthToken, removeAuthToken } from '@/support/helper'
import Message from '@/support/message'
import router from '@/router'
import { User } from '@/types/User'
import { Permission } from '@/types/Permission'

export const useUserStore = defineStore('UserStore', {
    state: (): User => {
        return {
            id: 0,
            username: '',
            avatar: '',
            email: '',
            remember_token: '',
            status: 0,
            permissions: [] as Permission[],
            roles: [] as string[],
            from: ''
        }
    },

    getters: {
        getId(): number {
            return this.id
        },
        getUsername(): string {
            return this.username
        },
        getAvatar(): string {
            return this.avatar
        },
        getRoles(): string[] | undefined {
            return this.roles
        },
        getPermissions(): Permission[] | undefined {
            return this.permissions
        },
        getFrom(): string | undefined {
            return this.from
        }
    },

    actions: {
        isSuperAdmin(): boolean {
            return this.id === 1
        },
        setUsername(username: string) {
            this.username = username
        },
        setId(id: number) {
            this.id = id
        },
        setRememberToken(token: string) {
            this.remember_token = token
        },
        setAvatar(avatar: string) {
            this.avatar = avatar
        },
        setRoles(roles: string[]) {
            this.roles = roles
        },
        setPermissions(permissions: Permission[]) {
            this.permissions = permissions
        },
        setEmail(email: string) {
            this.email = email
        },
        setStatus(status: number) {
            this.status = status
        },
        setFrom(form: string) {
            this.from = form
        },

        /**
         * login - 支持2FA
         */
        login(params: Object) {
            return new Promise((resolve, reject) => {
                http
                    .post('/login', params)
                    .then(response => {
                        const data = response.data.data

                        // 检查是否需要2FA设置
                        if (data.require_2fa_setup) {
                            // 返回2FA设置相关数据
                            resolve(data)
                            return
                        }

                        // 正常登录流程
                        if (data.token) {
                            rememberAuthToken(data.token)
                            this.setRememberToken(data.token)

                            // 获取用户信息后再resolve
                            this.getUserInfo()
                                .then(() => {
                                    resolve(data)
                                })
                                .catch(e => {
                                    reject(e)
                                })
                        } else {
                            resolve(data)
                        }
                    })
                    .catch(e => {
                        reject(e)
                    })
            })
        },

        /**
         * 完成2FA设置
         */
        completeTwoFactorSetup(params: { pending_token: string; code: string }) {
            return new Promise((resolve, reject) => {
                http
                    .post('/2fa', params)
                    .then(response => {
                        const data = response.data.data

                        if (data.token) {
                            rememberAuthToken(data.token)
                            this.setRememberToken(data.token)
                        }

                        resolve(data)
                    })
                    .catch(e => {
                        reject(e)
                    })
            })
        },

        setTwoFactorEnabled(userId: number, two_factor_enabled: boolean) {
            return new Promise((resolve, reject) => {
                http
                    .post(`/user/${userId}/two-factor-status`, { two_factor_enabled })
                    .then(response => {
                        const data = response.data.data

                        if (data.token) {
                            rememberAuthToken(data.token)
                            this.setRememberToken(data.token)
                        }
                        resolve(response.data)
                    })
                    .catch(e => {
                        reject(e)
                    })
            })
        },

        /**
         * logout
         */
        logout() {
            http
                .post('/logout')
                .then(() => {
                    removeAuthToken()
                    this.$reset()
                    router.push({ path: '/login' })
                })
                .catch(e => {
                    Message.error(e.message)
                })
        },

        /**
         * user info
         */
        getUserInfo() {
            return new Promise((resolve, reject) => {
                http
                    .get('/user/online')
                    .then((response: any) => {
                        const { id, username, email, avatar, permissions, roles, rememberToken, status, from } = response.data.data
                        // set user info
                        this.setId(id)
                        this.setUsername(username)
                        this.setEmail(email)
                        this.setRoles(roles)
                        this.setRememberToken(rememberToken)
                        this.setStatus(status)
                        this.setAvatar(avatar)
                        this.setPermissions(permissions)
                        this.setFrom(from)
                        resolve(response.data.data)
                    })
                    .catch(e => {
                        reject(e)
                    })
            })
        }
    }
})
