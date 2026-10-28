import Extend from 'flarum/common/extenders';
import SettingsPanel from './components/SettingsPanel';

export default [new Extend.Admin().customSetting(() => <SettingsPanel />, 100)];
