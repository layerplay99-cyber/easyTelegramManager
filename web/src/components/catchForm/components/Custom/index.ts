import Custom from './Custom.vue'
import { t } from '@/support/helper'

export default {
  name: 'custom',
  component: Custom,
  initialValues: {
    label: t('catchForm.designer.customComponent'),
    component: 'Custom',
    props: {}
  }
}
