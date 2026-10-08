const vi = {
  system: {
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
    name: 'Bảng điều khiển BotAdmin',
    chinese: 'Tiếng Trung',
    english: 'Tiếng Anh',
    thai: 'Tiếng Thái',
    vietnamese: 'Tiếng Việt',
    confirm: 'Xác nhận',
    cancel: 'Hủy',
    warning: 'Cảnh báo',
    next: 'Tiếp theo',
    prev: 'Trước',
    yes: 'Có',
    no: 'Không',
    add: 'Thêm',
    edit: 'Chỉnh sửa',
    delete: 'Xóa',
    detail: 'Chi tiết',
    finish: 'Hoàn thành',
    back: 'Quay lại',
    update: 'Cập nhật',
    search: 'Tìm kiếm',
    reset: 'Đặt lại'
  },

  login: {
    email: 'Email',
    username: 'Tên người dùng',
    password: 'Mật khẩu',
    sign_in: 'Đăng nhập',
    welcome: '👏Chào mừng trở lại',
    lost_password: 'Quên mật khẩu?',
    remember: 'Ghi nhớ đăng nhập',
    verify: {
      email: {
        required: 'Vui lòng nhập email trước',
        invalid: 'Địa chỉ email không hợp lệ'
      },

      username: {
        required: 'Vui lòng nhập tên người dùng trước'
      },

      password: {
        required: 'Vui lòng nhập mật khẩu trước'
      }
    }
  },

  register: {
    sign_up: 'Đăng ký'
  },

  telegram: {
    phone: {
      title: 'Quản lý số điện thoại',
      resourceId: 'ID tài nguyên',
      homeLink: 'Liên kết trang chủ',
      resourceCategory: 'Danh mục tài nguyên',
      resourceLink: 'Liên kết tài nguyên',
      resourceType: 'Loại tài nguyên',
      status: 'Trạng thái',
      createTime: 'Thời gian tạo',
      operation: 'Thao tác',
      enabled: 'Kích hoạt',
      disabled: 'Vô hiệu hóa',
      export: 'Xuất',
      import: 'Nhập',
      downloadTemplate: 'Tải mẫu',
      importProgress: 'Tiến trình nhập',
      selectPlaceholder: 'Vui lòng chọn',
      startDate: 'Ngày bắt đầu',
      endDate: 'Ngày kết thúc',
      to: 'đến',
      preparing: 'Đang chuẩn bị...',
      items: 'mục',
      success: 'Thành công',
      failed: 'Thất bại',
      uploading: 'Đang tải file lên...',
      importing: 'Đang nhập dữ liệu...',
      analyzing: 'Đang phân tích file...',
      importComplete: 'Nhập hoàn tất!',
      operationFailed: 'Thao tác thất bại',
      failedDetails: 'Chi tiết lỗi',
      linkPlaceholder: 'Nhập liên kết tài nguyên, nếu là liên kết Facebook, hệ thống sẽ cố gắng trích xuất FBID',
      facebookLinkQuery: 'Truy vấn liên kết trang chủ',
      facebookLinkPlaceholder: 'Nhập liên kết trang cá nhân Facebook, ví dụ: https://www.facebook.com/irfan.kos.39',
      queryId: 'Truy vấn ID',
      queryIdDesc: 'Nhập liên kết trang cá nhân Facebook, hệ thống sẽ tự động lấy ID người dùng và chuyển đổi thành định dạng chuẩn'
    },
    tUser: {
      title: 'Quản lý người dùng Telegram',
      username: 'Tên người dùng',
      name: 'Tên',
      country: 'Quốc gia',
      works: 'Công việc',
      age: 'Tuổi',
      phone: 'Điện thoại',
      status: 'Trạng thái',
      createTime: 'Thời gian tạo',
      operation: 'Thao tác'
    },
    sCanLog: {
      title: 'Nhật ký quét',
      scanTime: 'Thời gian quét',
      scanResult: 'Kết quả quét',
      scanType: 'Loại quét',
      targetId: 'ID mục tiêu',
      status: 'Trạng thái',
      operation: 'Thao tác'
    }
  },

  generate: {
    schema: {
      title: 'Tạo lược đồ',
      name: 'Tên lược đồ',
      name_verify: 'Vui lòng nhập tên lược đồ',
      engine: {
        name: 'Công cụ tìm kiếm',
        verify: 'Vui lòng chọn công cụ lược đồ',
        placeholder: 'Chọn công cụ lược đồ'
      },
      default_field: {
        name: 'Trường mặc định',
        created_at: 'Thời gian tạo',
        updated_at: 'Thời gian cập nhật',
        creator: 'Người tạo',
        delete_at: 'Xóa mềm'
      },
      comment: {
        name: 'Ghi chú lược đồ',
        verify: 'Vui lòng nhập ghi chú lược đồ'
      },

      structure: {
        title: 'Tạo cấu trúc lược đồ',
        field_name: {
          name: 'Tên trường',
          verify: 'Vui lòng nhập tên trường'
        },
        length: 'Độ dài',
        type: {
          name: 'Loại trường',
          placeholder: 'Chọn loại trường',
          verify: 'Vui lòng chọn loại trường'
        },
        form_label: 'Nhãn biểu mẫu',
        form_component: 'Thành phần',
        list: 'Danh sách',
        form: 'Biểu mẫu',
        unique: 'Duy nhất',
        search: 'Tìm kiếm',
        search_op: {
          name: 'Toán tử tìm kiếm',
          placeholder: 'Chọn toán tử tìm kiếm'
        },
        nullable: 'Có thể null',
        default: 'Mặc định',
        rules: {
          name: 'Quy tắc xác thực',
          placeholder: 'Chọn quy tắc xác thực'
        },
        operate: 'Thao tác',
        comment: 'Ghi chú trường'
      }
    },
    code: {
      title: 'Tạo mã',
      module: {
        name: 'Mô-đun',
        placeholder: 'Vui lòng chọn mô-đun',
        verify: 'Vui lòng chọn mô-đun trước'
      },
      controller: {
        name: 'Bộ điều khiển',
        placeholder: 'Vui lòng nhập tên bộ điều khiển',
        verify: 'Vui lòng nhập tên bộ điều khiển trước'
      },
      model: {
        name: 'Mô hình',
        placeholder: 'Vui lòng nhập tên mô hình',
        verify: 'Vui lòng nhập tên mô hình trước'
      },
      paginate: 'Phân trang'
    }
  },

  module: {
    create: 'Tạo mô-đun',
    update: 'Cập nhật mô-đun',
    form: {
      name: {
        title: 'Tên mô-đun',
        required: 'Cần tên mô-đun'
      },

      path: {
        title: 'Đường dẫn mô-đun',
        required: 'Cần đường dẫn mô-đun'
      },

      desc: {
        title: 'Mô tả'
      },

      keywords: {
        title: 'Từ khóa'
      },

      dirs: {
        title: 'Thư mục mặc định',
        Controller: 'Bộ điều khiển',
        Model: 'Mô hình',
        Database: 'Cơ sở dữ liệu',
        Request: 'Yêu cầu'
      }
    }
  },

  broadcast: {
    title: 'Gửi tin nhắn hàng loạt',
    selectBot: 'Chọn bot',
    selectBotPlaceholder: 'Vui lòng chọn bot',
    selectGroupCategory: 'Chọn danh mục nhóm',
    messageContent: 'Nội dung tin nhắn',
    messagePlaceholder: 'Vui lòng nhập nội dung tin nhắn',
    selectAll: 'Chọn tất cả (Tất cả các nhóm)',
    searchGroupName: 'Tìm kiếm tên nhóm',
    selected: 'Đã chọn',
    groups: 'nhóm',
    allGroups: 'Tất cả',
    groupCategory: 'Danh mục',
    groupName: 'Tên nhóm',
    noGroups: 'Không có nhóm',
    syncFirst: 'Không tìm thấy nhóm, vui lòng đồng bộ nhóm trước',
    confirmSend: 'Gửi',
    sendSuccess: 'Gửi tin nhắn thành công',
    sendFailed: 'Gửi tin nhắn thất bại',
    partialFailed: 'Một số tin nhắn gửi thất bại',
    selectBotRequired: 'Vui lòng chọn bot',
    messageRequired: 'Vui lòng nhập nội dung tin nhắn',
    selectGroupRequired: 'Vui lòng chọn ít nhất một nhóm',
    loadBotsFailed: 'Không thể tải danh sách bot',
    loadGroupsFailed: 'Không thể tải danh sách nhóm',
    loadGroupsRetry: 'Không thể tải danh sách nhóm, vui lòng thử lại sau',
    sendRetry: 'Gửi tin nhắn thất bại, vui lòng thử lại sau',
    photoUrl: 'Đường dẫn hình ảnh',
    photoUrlPlaceholder: 'Nhập URL hình ảnh hoặc tải lên',
    uploadPhoto: 'Tải lên hình ảnh',
    photoTip: 'Hỗ trợ jpg/jpeg/png/gif/webp, tối đa 5MB',
    caption: 'Chú thích hình ảnh',
    captionPlaceholder: 'Nhập chú thích hình ảnh (tùy chọn)',
    photoRequired: 'Vui lòng tải lên hình ảnh hoặc nhập URL',
    invalidFileType: 'Loại tệp không hợp lệ, chỉ hỗ trợ jpg/jpeg/png/gif/webp',
    fileSizeLimit: 'Kích thước tệp không được vượt quá 5MB',
    uploadSuccess: 'Tải lên hình ảnh thành công',
    uploadFailed: 'Tải lên hình ảnh thất bại'
  },

  groupSelect: {
    title: 'Chọn nhóm',
    messageType: 'Loại tin nhắn',
    messageTypePlaceholder: 'Vui lòng chọn loại tin nhắn',
    textType: 'Văn bản',
    mediaType: 'Hình ảnh và văn bản',
    photoType: 'Hình ảnh',
    selectCurrentPage: 'Chọn tất cả (Trang hiện tại)',
    confirmExecute: 'Thực hiện',
    operateSuccess: 'Thao tác thành công',
    operateFailed: 'Thao tác thất bại',
    partialFailed: 'Một số thao tác thất bại'
  }
}

export default vi
