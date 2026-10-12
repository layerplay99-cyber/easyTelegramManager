import { t } from '@/support/helper'

const basicAttr = (omit = []) => {
  const attr = [
    { label: t('catchForm.designer.customClass'), component: 'Input', name: 'props.class' },
    {
      label: t('catchForm.designer.customStyle'),
      component: 'JsonEdit',
      name: 'props.style',
      help: t('catchForm.designer.customStyleHelp'),
      props: {
        mode: 'dialog'
      }
    }
  ]

  return attr.filter((item) => !omit.includes(item.name))
}

export default basicAttr
