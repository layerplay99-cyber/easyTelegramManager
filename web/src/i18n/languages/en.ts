const en = {
  system: {
    name: 'BotAdmin Dashboard',
    chinese: 'Chinese',
    english: 'English',
    thai: 'Thai',
    vietnamese: 'Vietnamese',
    confirm: 'Confirm',
    cancel: 'Cancel',
    warning: 'Warning',
    next: 'Next',
    prev: 'Prev',
    yes: 'Y',
    no: 'N',
    add: 'Add',
    edit: 'Edit',
    delete: 'Delete',
    detail: 'Detail',
    finish: 'Finish',
    back: 'Back',
    update: 'Update',
    search: 'Search',
    reset: 'Reset'
  },

  login: {
    email: 'Email',
    password: 'Password',
    sign_in: 'Sign In',
    welcome: 'Welcome Back👏',
    lost_password: 'lost password?',
    remember: 'Remember me',
    verify: {
      email: {
        required: 'Please input email first',
        invalid: 'Email address is invalid'
      },

      password: {
        required: 'Please input password first'
      }
    }
  },

  register: {
    sign_up: 'Sign Up'
  },

  telegram: {
    phone: {
      title: 'Phone Management',
      resourceId: 'Resource ID',
      homeLink: 'Home Link',
      resourceCategory: 'Resource Category',
      resourceLink: 'Resource Link',
      resourceType: 'Resource Type',
      status: 'Status',
      createTime: 'Create Time',
      operation: 'Operation',
      enabled: 'Enabled',
      disabled: 'Disabled',
      export: 'Export',
      import: 'Import',
      downloadTemplate: 'Download Template',
      importProgress: 'Import Progress',
      selectPlaceholder: 'Please select',
      startDate: 'Start Date',
      endDate: 'End Date',
      to: 'to',
      preparing: 'Preparing...',
      items: 'items',
      success: 'Success',
      failed: 'Failed',
      uploading: 'Uploading file...',
      importing: 'Importing data...',
      analyzing: 'Analyzing file...',
      importComplete: 'Import complete!',
      operationFailed: 'Operation failed',
      failedDetails: 'Failed details',
      linkPlaceholder: 'Enter resource link, if it\'s a Facebook link, the system will try to extract FBID',
      facebookLinkQuery: 'Home Link Query',
      facebookLinkPlaceholder: 'Enter Facebook personal page link, e.g.: https://www.facebook.com/irfan.kos.39',
      queryId: 'Query ID',
      queryIdDesc: 'Enter Facebook personal page link, the system will automatically get user ID and convert to standard format'
    },
    tUser: {
      title: 'Telegram User Management',
      username: 'Username',
      name: 'Name',
      country: 'Country',
      works: 'Works',
      age: 'Age',
      phone: 'Phone',
      status: 'Status',
      createTime: 'Create Time',
      operation: 'Operation'
    },
    sCanLog: {
      title: 'Scan Log',
      scanTime: 'Scan Time',
      scanResult: 'Scan Result',
      scanType: 'Scan Type',
      targetId: 'Target ID',
      status: 'Status',
      operation: 'Operation'
    }
  },

  generate: {
    schema: {
      title: 'Create Schema',
      name: 'Schema Name',
      name_verify: 'please input schema name',
      engine: {
        name: 'Search Engine',
        verify: 'please select schema engine',
        placeholder: 'select schema engine'
      },
      default_field: {
        name: 'Default Field',
        created_at: 'Create time',
        updated_at: 'Update Time',
        creator: 'Creator',
        delete_at: 'SoftDelete'
      },
      comment: {
        name: 'Schema Comment',
        verify: 'please input schema comment'
      },

      structure: {
        title: 'Create Schema Structure',
        field_name: {
          name: 'Field Name',
          verify: 'please input field name'
        },
        length: 'Length',
        type: {
          name: 'Field Type',
          placeholder: 'select field type',
          verify: 'please select field type'
        },
        form_label: 'Form Label',
        form_component: 'Component',
        list: 'List',
        form: 'Form',
        unique: 'Unique',
        search: 'Search',
        search_op: {
          name: 'Search Operate',
          placeholder: 'select search operate'
        },
        nullable: 'Nullable',
        default: 'Default',
        rules: {
          name: 'Verify Rules',
          placeholder: 'select verify rules'
        },
        operate: 'Operate',
        comment: 'Field Comment'
      }
    },
    code: {
      title: 'Code Gen',
      module: {
        name: 'module',
        placeholder: 'please select module',
        verify: 'please select module first'
      },
      controller: {
        name: 'Controller',
        placeholder: 'please input controller name',
        verify: 'please input Controller name  first'
      },
      model: {
        name: 'Model',
        placeholder: 'please input model name',
        verify: 'please input model name  first'
      },
      paginate: 'Paginate'
    }
  },

  module: {
    create: 'Create Module',
    update: 'Update Module',
    form: {
      name: {
        title: 'Module Name',
        required: 'module name required'
      },

      path: {
        title: 'Module Path',
        required: 'module Path required'
      },

      desc: {
        title: 'Description'
      },

      keywords: {
        title: 'Keywords'
      },

      dirs: {
        title: 'Default Dirs',
        Controller: 'Controller',
        Model: 'Model',
        Database: 'Database',
        Request: 'Request'
      }
    }
  },

  broadcast: {
    title: 'Broadcast Message',
    selectBot: 'Select Bot',
    selectBotPlaceholder: 'Please select a bot',
    selectGroupCategory: 'Select Group Category',
    messageContent: 'Message Content',
    messagePlaceholder: 'Please enter the message content',
    selectAll: 'Select All (All Groups)',
    searchGroupName: 'Search Group Name',
    selected: 'Selected',
    groups: 'groups',
    allGroups: 'All',
    groupCategory: 'Category',
    groupName: 'Group Name',
    noGroups: 'No groups available',
    syncFirst: 'No groups found, please sync groups first',
    confirmSend: 'Send',
    sendSuccess: 'Message sent successfully',
    sendFailed: 'Message sending failed',
    partialFailed: 'Some messages failed to send',
    selectBotRequired: 'Please select a bot',
    messageRequired: 'Please enter message content',
    selectGroupRequired: 'Please select at least one group',
    loadBotsFailed: 'Failed to load bots',
    loadGroupsFailed: 'Failed to load groups',
    loadGroupsRetry: 'Failed to load groups, please try again later',
    sendRetry: 'Failed to send message, please try again later',
    photoUrl: 'Photo URL',
    photoUrlPlaceholder: 'Enter photo URL or upload photo',
    uploadPhoto: 'Upload Photo',
    photoTip: 'Supports jpg/jpeg/png/gif/webp, max 5MB',
    caption: 'Photo Caption',
    captionPlaceholder: 'Enter photo caption (optional)',
    photoRequired: 'Please upload photo or enter photo URL',
    invalidFileType: 'Invalid file type, only jpg/jpeg/png/gif/webp supported',
    fileSizeLimit: 'File size cannot exceed 5MB',
    uploadSuccess: 'Photo uploaded successfully',
    uploadFailed: 'Photo upload failed'
  },

  groupSelect: {
    title: 'Select Groups',
    messageType: 'Message Type',
    messageTypePlaceholder: 'Please select message type',
    textType: 'Text',
    mediaType: 'Media',
    photoType: 'Photo',
    selectCurrentPage: 'Select All (Current Page)',
    confirmExecute: 'Execute',
    operateSuccess: 'Operation successful',
    operateFailed: 'Operation failed',
    partialFailed: 'Some operations failed'
  }
}

export default en
