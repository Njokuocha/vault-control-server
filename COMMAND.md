# Vault Shell Commands

Vault Shell is an authenticated, virtual command console rooted at
`storage/app/private/vault`. It is available on every authenticated dashboard
route. Press **Ctrl + Shift + X** to open, hide, or show a console window.
The prompt starts at:

```text
VCS vcs:/root>
```

The console does not invoke Windows, PowerShell, or a system shell. Paths are
relative to the current directory unless they begin with `/root`. Quote paths
that contain spaces. Existing destinations are never overwritten.

| Command | Description | Example |
| --- | --- | --- |
| `cd [directory]` | Change to a directory. With no argument, print the current directory. | `cd reports` / `cd ..` |
| `dir [directory]` | List directories and files in the current or specified directory. | `dir` / `dir reports` |
| `mkdir [directory]` | Create a directory in the current or specified directory. Parent directories must already exist. | `mkdir reports` |
| `copy [source] [destination]` | Copy a file or directory recursively. If destination is an existing directory, copy into it; otherwise use it as the new path. | `copy note.txt archive` |
| `move [source] [destination]` | Move or rename a file or directory. If destination is an existing directory, move into it; otherwise use it as the new path. | `move note.txt archive` |
| `launch [file]` | Open a file in the Vault preview. Text is shown as text; supported images, PDFs, audio, and video use their matching preview. | `launch note.txt` |
| `launch vs` | Open another independent Vault Shell window. | `launch vs` |
| `del [file]` | Ask for `yes` or `no` before deleting the selected private file. The listing refreshes after deletion. | `del note.txt` |
| `trigger [text]` | Display supplied text. Arithmetic expressions are calculated; quote an expression to display it literally. | `trigger connected` / `trigger 3+3` / `trigger "3+3"` |
| `?help` | Display all available commands. | `?help` |
| `exit` | Close the current Vault Shell window. | `exit` |

For example, `trigger 3+3` displays `6`, while `trigger "3+3"` displays the
literal text `3+3`. `trigger connected` displays `connected`.

Drag a window by its title bar; use the full-screen control to expand it to the
viewport. Each window has its own current directory and can be closed
independently.

There is no `echo` or `start` command: use `trigger` and `launch` instead.
Commands such as `format`, `powershell`, and arbitrary system commands are not
available.

The virtual root is private and isolated from the rest of the application
filesystem. Paths cannot escape `/root`, hidden entries and symbolic links are
excluded, and copied directories containing symbolic links are rejected. Files
are served only through authenticated routes; active file types are not
executed in the preview.
