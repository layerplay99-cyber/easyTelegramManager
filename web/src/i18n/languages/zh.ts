const zh = {
  system: {
    name: '机器人管理系统',
    chinese: '中文',
    english: '英文',
    thai: '泰语',
    vietnamese: '越南语',
    confirm: '确定',
    cancel: '取消',
    warning: '警告',
    next: '下一步',
    prev: '上一步',
    yes: '是',
    no: '否',
    add: '新增',
    edit: '编辑',
    delete: '删除',
    detail: '详情',
    finish: '完成',
    back: '返回',
    update: '更新',
    search: '搜索',
    reset: '重置',
    profile: '个人信息',
    logout: '退出',
    logoutConfirm: '确定退出系统吗?',
    status: '状态',
    pleaseSelect: '请选择',
    operation: '操作',
    createTime: '创建时间',
    updateTime: '更新时间',
    none: '无',
    all: '全部',
    noData: '暂无数据',
    operateFailed: '操作失败',
    updateFailed: '更新失败',
    enabled: '启用',
    disabled: '禁用',
    submit: '提交'
  },

  table: {
    selected: '已选',
    selectedItems: '条数据',
    export: '导出',
    loose: '宽松',
    default: '默认',
    compact: '紧凑',
    columnSettings: '列设置'
  },

  nav: {
    searchMenu: '请输入菜单名称'
  },

  menu: {
    '/dashboard': '首页',
    '/permission': '权限管理',
    '/permission/departments': '部门管理',
    '/permission/jobs': '岗位管理',
    '/permission/permissions': '菜单管理',
    '/permission/roles': '角色管理',
    '/users': '用户管理',
    '/users/center': '个人中心',
    '/system': '系统管理',
    '/system/dictionary': '字典管理',
    '/system/dictionary/values/:id': '字典值管理',
    '/develop': '开发工具',
    '/develop/modules': '模块管理',
    '/develop/schemas': 'Schema',
    '/develop/generates': '生成代码',
    '/collect': '号码采集',
    '/collect/phone': '号码仓库',
    '/collect/sCanLog': '扫描记录',
    '/collect/telegramApiUser': '账号仓库',
    '/activity': '附加功能',
    '/activity/funs': '功能列表',
    '/activity/tconf': '三方配置',
    '/activity/tendpoint': '三方接口',
    '/activity/tconfendpoint': '上游接口配置',
    '/telegram': '机器人管理',
    '/telegram/apiuser': 'Telegram客服',
    '/telegram/bots': '机器人列表',
    '/telegram/groupgroups': '群分组',
    '/telegram/groups': '群列表',
    '/telegram/messageTemplate': '消息模板',
    '/telegram/emojis': '表情素材',
    '/wallet': '钱包管理',
    '/wallet/walletMember': '会员',
    '/wallet/walletAccount': '钱包',
    '/wallet/walletRechargeOrder': '充值订单',
    '/wallet/walletWithdrawOrder': '提现订单',
    '/wallet/walletLedger': '账本流水',
    '/wallet/walletChannel': '支付通道',
    '/wallet/walletRisk': '风控中心',
    '/wallet/walletLimit': '交易限额',
    '/wallet/walletRate': '汇率'
  },

  bots: {
    username: 'Bot用户名',
    description: 'Bot描述',
    activeStatus: '激活状态',
    thirdUpstream: '三方上游',
    thirdUnspecified: '未指定',
    thirdUnspecifiedCreate: '未指定（该机器人无法调用三方接口）',
    thirdTip: '该机器人调用三方接口时使用的上游（地址 + Token）。不同机器人可指向不同上游。',
    thirdUpdated: '已更新该机器人的三方上游',
    viewStatus: '查看状态',
    broadcast: '群发',
    featureConfig: '功能配置',
    activated: '已激活',
    pending: '待激活',
    activateSuccess: '机器人激活成功',
    deactivateSuccess: '机器人已关闭',
    getStatusFailed: '获取状态失败',
    webhookTitle: 'Webhook 状态',
    webhookUrl: '回调地址（Telegram 实际注册）',
    webhookUnset: '（未设置）',
    lastErrorTime: '最后错误时间',
    lastErrorMessage: '最后错误信息',
    pendingUpdates: '积压更新数',
    maxConnections: '最大连接数',
    allowedUpdates: '允许更新类型',
    customCert: '自建证书',
    notSetWebhook: '尚未设置 Webhook',
    webhookError: 'Webhook 异常',
    webhookOk: 'Webhook 正常',
    webhookNotSetDesc: '该机器人未向 Telegram 注册回调地址，请先在列表开启「激活」。',
    webhookErrorDesc: 'Telegram 最近一次投递失败：多为回调地址无法公网访问、非 HTTPS、证书问题或 secret_token 不匹配。',
    webhookOkDesc: 'Telegram 已成功注册回调地址，机器人可正常接收消息。',
    webhookTarget: 'Webhook 指向',
    webhookPlatform: '当前平台',
    webhookCustom: '自定义外链',
    webhookPlatformPlaceholder: '使用当前平台回调（无需填写）',
    webhookCustomPlaceholder: '请输入外部 Webhook URL',
    webhookAutoUse: '将自动使用当前平台回调地址：'
  },

  group: {
    name: '群名称',
    chatId: '群聊ID',
    groupName: '分组名称',
    batchSetGroup: '批量设置分组',
    config: '配置',
    feature: '功能',
    configTitle: '群组配置',
    merchantId: '商户ID',
    merchantIdPlaceholder: '请输入商户ID',
    customService: '客服',
    customersPlaceholder: '多个客服用逗号分隔',
    customersTip: '提示：多个客服请用逗号分隔',
    replyLang: '回复语言',
    replyLangPlaceholder: '请选择回复语言',
    welcome: '欢迎语',
    welcomePlaceholder: '请输入欢迎语',
    autoReply: '自动回复',
    keyword: '关键词',
    replyContent: '回复内容',
    addAutoReply: '添加自动回复',
    saveConfig: '保存配置',
    selectGroupCategory: '选择群分组',
    selectGroupPlaceholder: '请选择群聊'
  },

  account: {
    phone: '电话号码',
    nickname: '昵称',
    loginStatus: '登录状态',
    userStatus: '用户状态',
    joinCount: '入群数',
    import: '导入',
    login: '登录',
    logout: '注销',
    feature: '功能',
    syncGroups: '同步群',
    invalidFileType: '文件格式不正确，请上传Excel或CSV文件',
    uploadSuccess: '文件上传成功',
    uploadFailed: '上传失败',
    uploadFailedRetry: '上传失败，请稍后重试',
    loginStatusLogged: '已登录',
    loginStatusNotLogged: '未登录',
    loginStatusWaitingCode: '等待验证码',
    loginStatusWaitingVerify: '等待验证',
    loginStatusExpired: '登录过期',
    loginStatusWaitingLogin: '等待登录完成',
    loginStatusUnknown: '未知状态',
    codeTitle: '输入验证码',
    userInfo: '用户信息',
    code: '验证码',
    codePlaceholder: '请输入验证码',
    codeRequired: '请输入验证码',
    submitCode: '提交验证码',
    codeSubmitSuccess: '验证码提交成功',
    submitFailed: '提交失败',
    submitFailedRetry: '提交失败，请稍后重试',
    qrLoginTitle: '扫码登录',
    qrAlt: '登录二维码',
    qrTip: '请打开 Telegram 手机客户端，扫描二维码登录',
    qrNotFound: '未获取到登录二维码',
    twoFATitle: '输入两步验证密码',
    twoFA: '两步验证密码',
    twoFAPlaceholder: '请输入两步验证密码',
    twoFARequired: '请输入两步验证密码',
    twoFATip: '这是您在Telegram中设置的两步验证密码',
    submit2FA: '提交密码',
    userIdLost: '用户ID丢失',
    twoFASuccess: '2FA验证成功，登录完成',
    twoFAFailed: '2FA验证失败',
    loginSuccess: '登录成功！',
    need2FA: '需要输入两步验证密码'
  },

  // 功能（执行器 / 触发方式 / 分类）
  // 注意：必须挂在顶层，代码中使用的是 feature.triggers.*，
  // 之前误嵌在 system 内导致取不到而回落到原始 key（command、callback_query…）
  feature: {
    driverLabel: '执行器',
    triggerLabel: '触发方式',
    commandsLabel: '命令',
    triggers: {
      command: '斜杠命令',
      message: '消息',
      callback_query: '按钮回调',
      inline_query: '内联查询',
      inline_result: '内联结果',
      membership: '成员变化',
      poll_answer: '投票回调',
      chat_join_request: '入群申请',
      webhook: '三方推送',
      manual: '手动/接口',
    },
    categories: {
      system: '系统',
      custom: '客户端',
      bot: '机器人',
      realMan: '真人',
    },
  },

  login: {
    email: '邮箱',
    username: '账号',
    password: '密码',
    sign_in: '登录',
    welcome: '👏欢迎回来',
    lost_password: '忘记密码?',
    remember: '记住我',
    verify: {
      email: {
        required: '请先输入账号',
        invalid: '邮箱地址无效'
      },

      username: {
        required: '请先输入账号'
      },

      password: {
        required: '请先输入密码'
      }
    }
  },

  register: {
    sign_up: '注册'
  },

  telegram: {
    phone: {
      title: '手机号管理',
      resourceId: '资源ID',
      homeLink: '首页链接',
      resourceCategory: '资源分类',
      resourceLink: '资源链接',
      resourceType: '资源类型',
      status: '状态',
      createTime: '创建时间',
      operation: '操作',
      enabled: '启用',
      disabled: '禁用',
      export: '导出',
      import: '导入',
      downloadTemplate: '下载模板',
      importProgress: '导入进度',
      selectPlaceholder: '请选择',
      startDate: '开始日期',
      endDate: '结束日期',
      to: '至',
      preparing: '准备中...',
      items: '条',
      success: '成功',
      failed: '失败',
      uploading: '正在上传文件...',
      importing: '正在导入数据...',
      analyzing: '正在分析文件...',
      importComplete: '导入完成！',
      operationFailed: '操作失败',
      failedDetails: '失败详情',
      linkPlaceholder: '输入资源链接，如果是Facebook链接，系统将尝试提取FBID',
      facebookLinkQuery: '首页链接查询',
      facebookLinkPlaceholder: '输入Facebook个人主页链接，如：https://www.facebook.com/irfan.kos.39',
      queryId: '查询ID',
      queryIdDesc: '输入Facebook个人主页链接，系统将自动获取用户ID并转换为标准格式'
    },
    tUser: {
      title: 'Telegram用户管理',
      username: '用户名',
      name: '姓名',
      country: '国家',
      works: '工作',
      age: '年龄',
      phone: '手机号',
      status: '状态',
      createTime: '创建时间',
      operation: '操作'
    },
    sCanLog: {
      title: '扫描日志',
      scanTime: '扫描时间',
      scanResult: '扫描结果',
      scanType: '扫描类型',
      targetId: '目标ID',
      status: '状态',
      operation: '操作'
    }
  },
  generate: {
    schema: {
      title: '创建数据表',
      name: '表名称',
      name_verify: '请输入表名称',
      engine: {
        name: '表引擎',
        verify: '请选择表引擎',
        placeholder: '选择表引擎'
      },
      default_field: {
        name: '默认字段',
        created_at: '创建时间',
        updated_at: '更新时间',
        creator: '创建人',
        delete_at: '软删除'
      },
      comment: {
        name: '表注释',
        verify: '请填写表注释/说明'
      },

      structure: {
        title: '创建数据结构',
        field_name: {
          name: '字段名称',
          verify: '请填写字段名称'
        },
        length: '长度',
        type: {
          name: '类型',
          placeholder: '选择字段类型',
          verify: '请先选择字段类型'
        },
        form_label: '表单 Label',
        form_component: '表单组件',
        list: '列表',
        form: '表单',
        unique: '唯一',
        search: '查询',
        search_op: {
          name: '搜索操作符',
          placeholder: '选择搜索操作符'
        },
        nullable: 'nullable',
        default: '默认值',
        rules: {
          name: '验证规则',
          placeholder: '选择验证规则'
        },
        operate: '操作',
        comment: '字段注释'
      }
    },
    code: {
      title: '生成代码',
      module: {
        name: '所属模块',
        placeholder: '请选择模块',
        verify: '请选择模块'
      },
      controller: {
        name: '控制器',
        placeholder: '请输入控制器名称',
        verify: '请输入控制器名称'
      },
      model: {
        name: '模型',
        placeholder: '请输入模型名称',
        verify: '请输入模型名称'
      },
      paginate: '分页',
      menu: {
        name: '菜单名称',
        placeholder: '请输入菜单名称',
        verify: '请输入菜单名称'
      }
    }
  },

  module: {
    create: '创建模块',
    update: '更新模块',
    form: {
      name: {
        title: '模块名称',
        required: '请输入模块名称'
      },

      path: {
        title: '模块目录',
        required: '请输入模块目录'
      },

      desc: {
        title: '模块描述'
      },

      keywords: {
        title: '模块关键字'
      },

      dirs: {
        title: '默认目录',
        Controller: 'Controller 目录',
        Model: 'Model 目录',
        Database: 'Database 目录',
        Request: 'Request 目录'
      }
    }
  },

  broadcast: {
    title: '消息群发',
    selectBot: '选择Bot',
    selectBotPlaceholder: '请选择Bot',
    selectGroupCategory: '选择群分组',
    messageContent: '消息内容',
    messagePlaceholder: '请输入要发送的消息内容',
    selectAll: '全选（所有群组）',
    searchGroupName: '搜索群名称',
    selected: '已选择',
    groups: '个群组',
    allGroups: '全部',
    groupCategory: '分组',
    groupName: '群组名称',
    noGroups: '暂无可用群组',
    syncFirst: '未找到可用群组，请先同步群组',
    confirmSend: '确定发送',
    sendSuccess: '消息发送成功',
    sendFailed: '消息发送失败',
    partialFailed: '部分消息发送失败',
    selectBotRequired: '请选择Bot',
    messageRequired: '请输入消息内容',
    selectGroupRequired: '请至少选择一个群组',
    loadBotsFailed: '加载Bot列表失败',
    loadGroupsFailed: '加载群组失败',
    loadGroupsRetry: '加载群组失败，请稍后重试',
    sendRetry: '发送消息失败，请稍后重试',
    photoUrl: '图片地址',
    photoUrlPlaceholder: '请输入图片URL或上传图片',
    uploadPhoto: '上传图片',
    photoTip: '支持 jpg/jpeg/png/gif/webp 格式，文件大小不超过 5MB',
    caption: '图片描述',
    captionPlaceholder: '请输入图片描述（可选）',
    photoRequired: '请上传图片或输入图片URL',
    invalidFileType: '不支持的文件类型，仅支持 jpg/jpeg/png/gif/webp',
    fileSizeLimit: '文件大小不能超过 5MB',
    uploadSuccess: '图片上传成功',
    uploadFailed: '图片上传失败'
  },

  groupSelect: {
    title: '选择群组',
    messageType: '消息类型',
    messageTypePlaceholder: '请选择消息类型',
    textType: '文字',
    mediaType: '图文',
    photoType: '图片',
    selectCurrentPage: '全选（当前页）',
    confirmExecute: '确定执行',
    operateSuccess: '操作成功',
    operateFailed: '操作失败',
    partialFailed: '部分操作失败'
  }
}

export default zh
