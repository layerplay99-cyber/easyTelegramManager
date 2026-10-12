import { recursionDelete } from '@/components/catchForm/support'
import { t } from '@/support/helper'

const basicAttr = (omit = [], moreAttrs = []) => {
  const attr = [
    { label: t('catchForm.designer.label'), component: 'Input', name: 'label' },
    {
      label: t('catchForm.designer.uniqueKey'),
      component: 'Input',
      name: 'name',
      help: t('catchForm.designer.uniqueKeyHelp')
    },
    { label: t('catchForm.designer.fieldHelp'), component: 'Textarea', name: 'help' },
    {
      label: t('catchForm.designer.placeholder'),
      component: 'Input',
      name: 'props.placeholder',
      designKey: 'form-ekRL'
    },
    { label: t('catchForm.designer.initialValue'), component: 'Input', name: 'initialValue' },
    {
      component: 'Grid',
      children: [
        { label: t('catchForm.designer.required'), component: 'Switch', name: 'required' },
        { label: t('catchForm.designer.readonly'), component: 'Switch', name: 'props.readonly' },
        { label: t('catchForm.designer.disabled'), component: 'Switch', name: 'props.disabled' },
        { label: t('catchForm.designer.hidden'), component: 'Switch', name: 'hidden' },
        { label: t('catchForm.designer.hideLabel'), component: 'Switch', name: 'hideLabel' }
      ],
      props: {
        columns: 3,
        'row-gap': 0,
        'column-gap': 20
      },
      designKey: 'form-R003',
      name: 'cNmCuu',
      style: {
        marginBottom: 0
      }
    }
  ]

  const omitAttrs = recursionDelete(attr, (item) => !omit.includes(item.name))

  return [...omitAttrs, ...moreAttrs]
}

export default basicAttr
