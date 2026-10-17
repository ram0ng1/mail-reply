import Extend from 'flarum/common/extenders';
import SettingsPanel from './components/SettingsPanel';

export default [new Extend.Admin().customSetting(() => m(SettingsPanel), 100)];
