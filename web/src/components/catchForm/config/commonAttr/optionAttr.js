import apiAttr from './apiAttr'
import { t } from '@/support/helper'

export default [
  {
    component: 'Divider',
    props: {
      title: t('catchForm.designer.optionSettings'),
      contentPosition: 'center'
    },
    designKey: 'design-gSnX',
    name: 'form-xDEe',
    style: {
      marginTop: '40px'
    }
  },
  {
    label: t('catchForm.designer.labelKey'),
    component: 'Input',
    name: 'props.labelKey',
    designKey: 'form-X6hs'
  },
  {
    label: t('catchForm.designer.valueKey'),
    component: 'Input',
    name: 'props.valueKey',
    designKey: 'form-STkl'
  },
  {
    label: t('catchForm.designer.dataMode'),
    component: 'Radio',
    name: 'props.mode',
    props: {
      mode: 'static',
      options: [
        {
          label: t('catchForm.designer.staticMode'),
          value: 'static'
        },
        {
          label: t('catchForm.designer.remoteMode'),
          value: 'remote'
        }
      ],
      optionType: 'button',
      space: 0
    },
    designKey: 'form-PLpj'
  },
  {
    label: t('catchForm.designer.staticOptions'),
    name: 'props.options',
    component: 'FormList',
    hidden: '{{$values.props.mode!=="static"}}',
    children: [
      {
        label: t('catchForm.designer.optionName'),
        name: '{{$values.props.labelKey}}',
        component: 'Input',
        props: {
          placeholder: t('catchForm.designer.pleaseInput')
        },
        designKey: 'form-LnGh'
        // initialValue: "{{ '选项' + ($index+1) }}"
      },
      {
        label: t('catchForm.designer.optionValue'),
        name: '{{$values.props.valueKey}}',
        component: 'Input',
        props: {},
        designKey: 'form-HYtW'
        // initialValue: "{{ 'value' + ($index+1) }}"
      }
    ],
    designKey: 'form-Iwpd',
    props: {
      mode: 'table',
      newItemDefaults: `{{ (index) => ({ [$values.props.labelKey]: \`${t('catchForm.designer.optionPrefix')}\${index + 1}\`, [$values.props.valueKey]: \`value\${index + 1}\` }) }}`
    }
  },
  {
    component: 'Card',
    props: {
      // header: '远程数据'
    },
    designKey: 'id-pGeN',
    name: 'form-6vzT',
    hidden: '{{$values.props.mode==="static"}}',
    children: apiAttr
  }
]
