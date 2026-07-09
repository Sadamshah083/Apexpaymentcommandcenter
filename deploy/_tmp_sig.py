from deploy._ssh import REMOTE_APP, connect, sudo_run
import inspect
print(inspect.signature(sudo_run))
print(inspect.getsource(sudo_run)[:800])