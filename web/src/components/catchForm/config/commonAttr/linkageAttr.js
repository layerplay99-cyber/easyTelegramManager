import { t } from '@/support/helper'

export default [
  {
    component: 'Alert',
    props: {
      type: 'success',
      description: t('catchForm.designer.linkageAlert'),
      closable: true,
      'show-icon': true
    },
    designKey: 'design-MQPU',
    name: 'form-Oqi5'
  },
  {
    label: t('catchForm.designer.valueLinkage'),
    help: t('catchForm.designer.valueLinkageHelp'),
    name: 'change',
    component: 'FormList',
    children: [
      {
        label: t('catchForm.designer.targetField'),
        name: 'target',
        component: 'Input',
        props: {}
      },
      {
        label: t('catchForm.designer.value'),
        name: 'value',
        component: 'Input',
        props: {}
      }
    ],
    props: {
      mode: 'card'
    }
  }
]
