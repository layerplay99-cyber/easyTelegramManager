import { t } from '@/support/helper'

export default [
  {
    label: 'url',
    component: 'Input',
    name: 'props.api.url',
    initialValue: '/current/query/article',
    designKey: 'form-UrE8'
  },
  {
    label: t('catchForm.designer.requestMethod'),
    component: 'Radio',
    name: 'props.api.method',
    props: {
      mode: 'static',
      autoSelectedFirst: true,
      options: [
        {
          label: 'GET',
          value: 'GET'
        },
        {
          label: 'POST',
          value: 'POST'
        },
        {
          label: 'PUT',
          value: 'PUT'
        },
        {
          label: 'DELETE',
          value: 'DELETE'
        }
      ]
    },
    designKey: 'form-nOpD'
  },
  {
    label: t('catchForm.designer.requestParams'),
    component: 'JsonEdit',
    name: 'props.api.params',
    hidden: '{{ ["POST","PUT"].includes($values.props.api.method) }}',
    initialValue: {},
    props: {
      mode: 'dialog'
    }
  },
  {
    label: t('catchForm.designer.requestParams'),
    component: 'JsonEdit',
    name: 'props.api.data',
    hidden: '{{ ["GET","DELETE"].includes($values.props.api.method) }}',
    initialValue: {},
    props: {
      mode: 'dialog'
    }
  },
  {
    label: t('catchForm.designer.dataPath'),
    component: 'Input',
    name: 'props.api.dataPath',
    initialValue: 'data',
    designKey: 'form-UrE8'
  }
]
