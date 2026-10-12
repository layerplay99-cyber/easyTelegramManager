import { t } from '@/support/helper'

const mergeAttr = (attrConfig) => {
  const { basic = [], high = [], linkage = [] } = attrConfig

  const attrs = [
    {
      component: 'Collapse',
      name: 'mergeAttr',
      children: [
        {
          title: t('catchForm.designer.commonAttr'),
          name: 'basic',
          checked: true,
          children: basic
        },
        {
          title: t('catchForm.designer.advancedAttr'),
          name: 'high',
          children: high
        },
        {
          title: t('catchForm.designer.linkageRule'),
          name: 'linkage',
          children: linkage
        }
      ]
    }
  ]

  return attrs
}

export default mergeAttr
