const th = {
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
    name: 'แดชบอร์ด BotAdmin',
    chinese: 'จีน',
    english: 'อังกฤษ',
    thai: 'ไทย',
    vietnamese: 'เวียดนาม',
    confirm: 'ยืนยัน',
    cancel: 'ยกเลิก',
    warning: 'คำเตือน',
    next: 'ถัดไป',
    prev: 'ก่อนหน้า',
    yes: 'ใช่',
    no: 'ไม่',
    add: 'เพิ่ม',
    edit: 'แก้ไข',
    delete: 'ลบ',
    detail: 'รายละเอียด',
    finish: 'เสร็จสิ้น',
    back: 'กลับ',
    update: 'อัปเดต',
    search: 'ค้นหา',
    reset: 'รีเซ็ต'
  },

  login: {
    email: 'อีเมล',
    username: 'ชื่อผู้ใช้',
    password: 'รหัสผ่าน',
    sign_in: 'เข้าสู่ระบบ',
    welcome: '👏ยินดีต้อนรับกลับ',
    lost_password: 'ลืมรหัสผ่าน?',
    remember: 'จดจำฉัน',
    verify: {
      email: {
        required: 'กรุณาใส่อีเมลก่อน',
        invalid: 'ที่อยู่อีเมลไม่ถูกต้อง'
      },

      username: {
        required: 'กรุณาใส่ชื่อผู้ใช้ก่อน'
      },

      password: {
        required: 'กรุณาใส่รหัสผ่านก่อน'
      }
    }
  },

  register: {
    sign_up: 'สมัครสมาชิก'
  },

  telegram: {
    phone: {
      title: 'จัดการหมายเลขโทรศัพท์',
      resourceId: 'รหัสทรัพยากร',
      homeLink: 'ลิงก์หน้าแรก',
      resourceCategory: 'หมวดหมู่ทรัพยากร',
      resourceLink: 'ลิงก์ทรัพยากร',
      resourceType: 'ประเภททรัพยากร',
      status: 'สถานะ',
      createTime: 'เวลาสร้าง',
      operation: 'การดำเนินการ',
      enabled: 'เปิดใช้งาน',
      disabled: 'ปิดใช้งาน',
      export: 'ส่งออก',
      import: 'นำเข้า',
      downloadTemplate: 'ดาวน์โหลดแม่แบบ',
      importProgress: 'ความคืบหน้าการนำเข้า',
      selectPlaceholder: 'กรุณาเลือก',
      startDate: 'วันที่เริ่มต้น',
      endDate: 'วันที่สิ้นสุด',
      to: 'ถึง',
      preparing: 'กำลังเตรียม...',
      items: 'รายการ',
      success: 'สำเร็จ',
      failed: 'ล้มเหลว',
      uploading: 'กำลังอัปโหลดไฟล์...',
      importing: 'กำลังนำเข้าข้อมูล...',
      analyzing: 'กำลังวิเคราะห์ไฟล์...',
      importComplete: 'นำเข้าเสร็จสิ้น!',
      operationFailed: 'การดำเนินการล้มเหลว',
      failedDetails: 'รายละเอียดความล้มเหลว',
      linkPlaceholder: 'ใส่ลิงก์ทรัพยากร หากเป็นลิงก์ Facebook ระบบจะพยายามแยกข้อมูล FBID',
      facebookLinkQuery: 'การค้นหาลิงก์หน้าแรก',
      facebookLinkPlaceholder: 'ใส่ลิงก์หน้าส่วนตัว Facebook เช่น: https://www.facebook.com/irfan.kos.39',
      queryId: 'ค้นหา ID',
      queryIdDesc: 'ใส่ลิงก์หน้าส่วนตัว Facebook ระบบจะดึง ID ผู้ใช้โดยอัตโนมัติและแปลงเป็นรูปแบบมาตรฐาน'
    },
    tUser: {
      title: 'จัดการผู้ใช้ Telegram',
      username: 'ชื่อผู้ใช้',
      name: 'ชื่อ',
      country: 'ประเทศ',
      works: 'งาน',
      age: 'อายุ',
      phone: 'โทรศัพท์',
      status: 'สถานะ',
      createTime: 'เวลาสร้าง',
      operation: 'การดำเนินการ'
    },
    sCanLog: {
      title: 'บันทึกการสแกน',
      scanTime: 'เวลาสแกน',
      scanResult: 'ผลการสแกน',
      scanType: 'ประเภทการสแกน',
      targetId: 'รหัสเป้าหมาย',
      status: 'สถานะ',
      operation: 'การดำเนินการ'
    }
  },

  generate: {
    schema: {
      title: 'สร้างสคีมา',
      name: 'ชื่อสคีมา',
      name_verify: 'กรุณาใส่ชื่อสคีมา',
      engine: {
        name: 'เครื่องมือค้นหา',
        verify: 'กรุณาเลือกเครื่องมือสคีมา',
        placeholder: 'เลือกเครื่องมือสคีมา'
      },
      default_field: {
        name: 'ฟิลด์เริ่มต้น',
        created_at: 'เวลาสร้าง',
        updated_at: 'เวลาอัปเดต',
        creator: 'ผู้สร้าง',
        delete_at: 'การลบแบบอ่อน'
      },
      comment: {
        name: 'หมายเหตุสคีมา',
        verify: 'กรุณาใส่หมายเหตุสคีมา'
      },

      structure: {
        title: 'สร้างโครงสร้างสคีมา',
        field_name: {
          name: 'ชื่อฟิลด์',
          verify: 'กรุณาใส่ชื่อฟิลด์'
        },
        length: 'ความยาว',
        type: {
          name: 'ประเภทฟิลด์',
          placeholder: 'เลือกประเภทฟิลด์',
          verify: 'กรุณาเลือกประเภทฟิลด์'
        },
        form_label: 'ป้ายกำกับฟอร์ม',
        form_component: 'คอมโพเนนต์',
        list: 'รายการ',
        form: 'ฟอร์ม',
        unique: 'เฉพาะ',
        search: 'ค้นหา',
        search_op: {
          name: 'ตัวดำเนินการค้นหา',
          placeholder: 'เลือกตัวดำเนินการค้นหา'
        },
        nullable: 'สามารถเป็น null',
        default: 'ค่าเริ่มต้น',
        rules: {
          name: 'กฎการตรวจสอบ',
          placeholder: 'เลือกกฎการตรวจสอบ'
        },
        operate: 'ดำเนินการ',
        comment: 'หมายเหตุฟิลด์'
      }
    },
    code: {
      title: 'สร้างโค้ด',
      module: {
        name: 'โมดูล',
        placeholder: 'กรุณาเลือกโมดูล',
        verify: 'กรุณาเลือกโมดูลก่อน'
      },
      controller: {
        name: 'คอนโทรลเลอร์',
        placeholder: 'กรุณาใส่ชื่อคอนโทรลเลอร์',
        verify: 'กรุณาใส่ชื่อคอนโทรลเลอร์ก่อน'
      },
      model: {
        name: 'โมเดล',
        placeholder: 'กรุณาใส่ชื่อโมเดล',
        verify: 'กรุณาใส่ชื่อโมเดลก่อน'
      },
      paginate: 'แบ่งหน้า'
    }
  },

  module: {
    create: 'สร้างโมดูล',
    update: 'อัปเดตโมดูล',
    form: {
      name: {
        title: 'ชื่อโมดูล',
        required: 'ต้องการชื่อโมดูล'
      },

      path: {
        title: 'เส้นทางโมดูล',
        required: 'ต้องการเส้นทางโมดูล'
      },

      desc: {
        title: 'คำอธิบาย'
      },

      keywords: {
        title: 'คำสำคัญ'
      },

      dirs: {
        title: 'ไดเรกทอรีเริ่มต้น',
        Controller: 'คอนโทรลเลอร์',
        Model: 'โมเดล',
        Database: 'ฐานข้อมูล',
        Request: 'คำขอ'
      }
    }
  },

  broadcast: {
    title: 'ส่งข้อความแบบกระจาย',
    selectBot: 'เลือกบอท',
    selectBotPlaceholder: 'กรุณาเลือกบอท',
    selectGroupCategory: 'เลือกหมวดหมู่กลุ่ม',
    messageContent: 'เนื้อหาข้อความ',
    messagePlaceholder: 'กรุณากรอกเนื้อหาข้อความ',
    selectAll: 'เลือกทั้งหมด (ทุกกลุ่ม)',
    searchGroupName: 'ค้นหาชื่อกลุ่ม',
    selected: 'เลือกแล้ว',
    groups: 'กลุ่ม',
    allGroups: 'ทั้งหมด',
    groupCategory: 'หมวดหมู่',
    groupName: 'ชื่อกลุ่ม',
    noGroups: 'ไม่มีกลุ่ม',
    syncFirst: 'ไม่พบกลุ่ม กรุณาซิงค์กลุ่มก่อน',
    confirmSend: 'ส่ง',
    sendSuccess: 'ส่งข้อความสำเร็จ',
    sendFailed: 'ส่งข้อความล้มเหลว',
    partialFailed: 'ส่งข้อความบางส่วนล้มเหลว',
    selectBotRequired: 'กรุณาเลือกบอท',
    messageRequired: 'กรุณากรอกเนื้อหาข้อความ',
    selectGroupRequired: 'กรุณาเลือกอย่างน้อยหนึ่งกลุ่ม',
    loadBotsFailed: 'โหลดบอทล้ศเหลว',
    loadGroupsFailed: 'โหลดกลุ่มล้มเหลว',
    loadGroupsRetry: 'โหลดกลุ่มล้มเหลว กรุณาลองใหม่อีกครั้ง',
    sendRetry: 'ส่งข้อความล้มเหลว กรุณาลองใหม่อีกครั้ง',
    photoUrl: 'ที่อยู่รูปภาพ',
    photoUrlPlaceholder: 'กรุณาป้อน URL รูปภาพหรืออัปโหลดรูปภาพ',
    uploadPhoto: 'อัปโหลดรูปภาพ',
    photoTip: 'รองรับ jpg/jpeg/png/gif/webp ขนาดไม่เกิน 5MB',
    caption: 'คำอธิบายรูปภาพ',
    captionPlaceholder: 'กรุณากรอกคำอธิบายรูปภาพ (เลือกได้)',
    photoRequired: 'กรุณาอัปโหลดรูปภาพหรือป้อน URL',
    invalidFileType: 'ไม่รองรับไฟล์ชนิดนี้ รองรับเฉพาะ jpg/jpeg/png/gif/webp',
    fileSizeLimit: 'ขนาดไฟล์ต้องไม่เกิน 5MB',
    uploadSuccess: 'อัปโหลดรูปภาพสำเร็จ',
    uploadFailed: 'อัปโหลดรูปภาพล้มเหลว'
  },

  groupSelect: {
    title: 'เลือกกลุ่ม',
    messageType: 'ประเภทข้อความ',
    messageTypePlaceholder: 'กรุณาเลือกประเภทข้อความ',
    textType: 'ข้อความ',
    mediaType: 'รูปภาพและข้อความ',
    photoType: 'รูปภาพ',
    selectCurrentPage: 'เลือกทั้งหมด (หน้านี้)',
    confirmExecute: 'ดำเนินการ',
    operateSuccess: 'ดำเนินการสำเร็จ',
    operateFailed: 'ดำเนินการล้มเหลว',
    partialFailed: 'ดำเนินการบางส่วนล้มเหลว'
  }
}

export default th
